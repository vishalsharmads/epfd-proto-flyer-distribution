<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Areas\LetterboxEstimator;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Exceptions\DomainProblem;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\User;
use App\Support\Geo;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;

/**
 * Distribution campaign lifecycle shared by the web wizard and the REST API:
 * create/autosave drafts, replace areas (each re-estimated server-side), price, validate for payment.
 */
final class CampaignService
{
    public function __construct(
        private readonly LetterboxEstimator $estimator,
        private readonly PricingService $pricing,
    ) {}

    public function createDraft(Business $business, User $user, array $attrs = []): Campaign
    {
        $tz = config('epfd.timezone');

        $campaign = Campaign::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'reference' => Reference::make('CMP', 'campaigns'),
            'name' => $attrs['name'] ?? $this->uniqueName($business, 'Campaign '.now($tz)->format('j-M')),
            'status' => 'draft',
            'step' => 1,
            'pickup_address' => $attrs['pickup_address'] ?? $business->address,
            'pickup_geom' => $this->pickupGeom($attrs, $business),
            'available_from' => $attrs['available_from'] ?? now($tz)->toDateString(),
            ...array_intersect_key($attrs, array_flip(['include_houses', 'include_units', 'coverage', 'material', 'notes', 'print_order_id', 'source_campaign_id'])),
        ]);

        // Reload so database defaults (include_houses, coverage…) are populated.
        return $campaign->fresh() ?? $campaign;
    }

    /** Re-run: copy a past campaign's areas/settings into a new draft. */
    public function rerun(Campaign $source, User $user): Campaign
    {
        $copy = $this->createDraft($source->business, $user, [
            'name' => $this->uniqueName($source->business, $source->name.' (re-run)'),
            'include_houses' => $source->include_houses,
            'include_units' => $source->include_units,
            'coverage' => $source->coverage,
            'material' => $source->material,
            'notes' => $source->notes,
            'pickup_address' => $source->pickup_address,
            'source_campaign_id' => $source->id,
        ]);
        DB::statement('UPDATE campaigns SET pickup_geom = (SELECT pickup_geom FROM campaigns WHERE id = ?) WHERE id = ?', [$source->id, $copy->id]);
        $areas = $source->areas()->withGeoJson()->get()->map(fn (CampaignArea $a) => [
            'name' => $a->name, 'draw_mode' => $a->draw_mode, 'suburb_id' => $a->suburb_id, 'geometry' => $a->geoJson(),
        ])->all();
        $this->syncAreas($copy->fresh(), $areas);

        return $copy->fresh();
    }

    /**
     * Replace all areas. Each polygon is validated and estimated on the server (never trusts client counts).
     *
     * @param  list<array{id?:int|null, name?:string, draw_mode?:string, suburb_id?:int|null, geometry: array<string,mixed>}>  $areas
     */
    public function syncAreas(Campaign $campaign, array $areas): Campaign
    {
        $pickup = $this->pickupLngLat($campaign);
        $rows = [];
        foreach (array_values($areas) as $i => $area) {
            $r = $this->estimator->estimate($area['geometry'], $campaign->include_houses, $campaign->include_units, $pickup);
            $est = $r['estimate'];
            $fee = $est->isEmpty() ? 0 : $this->pricing->distribution([
                'houses' => $est->houses, 'units' => $est->units, 'coverage' => $campaign->coverage, 'material' => $campaign->material,
            ])->totalCents;
            $rows[] = [
                'name' => trim((string) ($area['name'] ?? '')) ?: 'Run area '.($i + 1),
                'position' => $i + 1,
                'draw_mode' => in_array($area['draw_mode'] ?? null, ['pins', 'trace', 'suburb'], true) ? $area['draw_mode'] : 'pins',
                'suburb_id' => $area['suburb_id'] ?? null,
                'geom' => Geo::fromGeoJson($r['polygon']),
                'polygon_hash' => $r['hash'],
                'houses' => $est->houses,
                'units' => $est->units,
                'distance_km' => $est->distanceKm,
                'fee_cents' => $fee,
            ];
        }

        DB::transaction(function () use ($campaign, $rows) {
            $campaign->areas()->delete();
            foreach ($rows as $row) {
                $campaign->areas()->create($row);
            }
        });

        return $this->reprice($campaign->fresh());
    }

    /** Recalculate totals from areas + current coverage/material/promo. */
    public function reprice(Campaign $campaign): Campaign
    {
        $areas = $campaign->areas()->get(['houses', 'units', 'distance_km']);
        $houses = (int) $areas->sum('houses');
        $units = (int) $areas->sum('units');
        $quote = $this->pricing->distribution([
            'houses' => $houses,
            'units' => $units,
            'coverage' => $campaign->coverage,
            'material' => $campaign->material,
            'promo' => $this->promoOrNull($campaign->promo_code),
        ]);
        $billable = (int) $quote->meta['letterboxes'];
        $campaign->forceFill([
            'houses' => $houses,
            'units' => $units,
            'letterboxes' => $billable,
            'distance_km' => round((float) $areas->sum('distance_km'), 2),
            'subtotal_cents' => $billable > 0 ? $quote->subtotalCents : 0,
            'gst_cents' => $billable > 0 ? $quote->gstCents : 0,
            'total_cents' => $billable > 0 ? $quote->totalCents : 0,
            'pricing' => $quote->toArray(),
        ])->save();

        return $campaign;
    }

    public function update(Campaign $campaign, array $attrs): Campaign
    {
        if (! $campaign->isDraft()) {
            throw new DomainProblem('conflict', 'Only draft campaigns can be edited.', 409);
        }
        if (isset($attrs['name']) && ! $this->nameAvailable($campaign->business, $attrs['name'], $campaign->id)) {
            throw new DomainProblem('validation_failed', 'You already have a campaign with this name.', 422, extra: ['errors' => ['name' => ['You already have a campaign with this name.']]]);
        }
        if (isset($attrs['pickup_lng'], $attrs['pickup_lat'])) {
            $campaign->pickup_geom = Geo::point((float) $attrs['pickup_lng'], (float) $attrs['pickup_lat']);
        }
        if (array_key_exists('promo_code', $attrs) && $attrs['promo_code']) {
            PricingFactory::promo($attrs['promo_code'], 'distribution');
        }
        $campaign->fill(array_intersect_key($attrs, array_flip([
            'name', 'step', 'include_houses', 'include_units', 'coverage', 'material', 'pickup_address', 'available_from', 'notes', 'promo_code', 'pickup_point_id',
        ])));
        $campaign->save();

        $togglesChanged = $campaign->wasChanged(['include_houses', 'include_units']);
        $pickupChanged = isset($attrs['pickup_lng']);
        if (($togglesChanged || $pickupChanged) && $campaign->areas()->exists()) {
            $areas = $campaign->areas()->withGeoJson()->get()->map(fn (CampaignArea $a) => [
                'name' => $a->name, 'draw_mode' => $a->draw_mode, 'suburb_id' => $a->suburb_id, 'geometry' => $a->geoJson(),
            ])->all();

            return $this->syncAreas($campaign->fresh(), $areas);
        }

        return $this->reprice($campaign->fresh());
    }

    /** Validation before payment: name, pickup, ≥1 area with letterboxes, T&Cs handled by caller. */
    public function assertReadyForPayment(Campaign $campaign): void
    {
        $errors = [];
        if (! $campaign->areas()->exists()) {
            $errors['areas'] = ['Add at least one area.'];
        } elseif ($campaign->areas()->where(DB::raw('houses + units'), 0)->exists()) {
            $errors['areas'] = ['One of your areas has no letterboxes. Redraw it or delete it.'];
        }
        if ($campaign->letterboxes <= 0) {
            $errors['coverage'] = ['The selected coverage has no letterboxes in your areas.'];
        }
        if (! $campaign->pickup_address || $campaign->pickup_geom === null) {
            $errors['pickup_address'] = ['Choose a pickup address.'];
        }
        if (! $campaign->available_from) {
            $errors['available_from'] = ['Choose an "Available from" date.'];
        }
        if ($errors) {
            throw new DomainProblem('validation_failed', 'The campaign is not ready for payment.', 422, 'Validation failed', ['errors' => $errors]);
        }
    }

    public function nameAvailable(Business $business, string $name, ?int $ignoreId = null): bool
    {
        return ! Campaign::where('business_id', $business->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function uniqueName(Business $business, string $base): string
    {
        $name = $base;
        $n = 2;
        while (! $this->nameAvailable($business, $name)) {
            $name = "{$base} ({$n})";
            $n++;
        }

        return $name;
    }

    /** @return array{lng: float, lat: float}|null */
    public function pickupLngLat(Campaign $campaign): ?array
    {
        $p = DB::selectOne('SELECT ST_X(pickup_geom) AS lng, ST_Y(pickup_geom) AS lat FROM campaigns WHERE id = ?', [$campaign->id]);

        return $p && $p->lng !== null ? ['lng' => (float) $p->lng, 'lat' => (float) $p->lat] : null;
    }

    private function pickupGeom(array $attrs, Business $business): mixed
    {
        if (isset($attrs['pickup_lng'], $attrs['pickup_lat'])) {
            return Geo::point((float) $attrs['pickup_lng'], (float) $attrs['pickup_lat']);
        }
        $p = DB::selectOne('SELECT ST_X(geom) AS lng, ST_Y(geom) AS lat FROM businesses WHERE id = ?', [$business->id]);

        return $p && $p->lng !== null ? Geo::point((float) $p->lng, (float) $p->lat) : null;
    }

    /** @return array{code:string,type:string,value:int}|null */
    private function promoOrNull(?string $code): ?array
    {
        try {
            return PricingFactory::promo($code, 'distribution');
        } catch (DomainProblem) {
            return null;
        }
    }
}
