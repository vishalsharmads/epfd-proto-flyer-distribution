<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Campaigns\CampaignService;
use App\Domain\Design\DesignService;
use App\Domain\Payments\PaymentGatewayManager;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Models\Artwork;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\DesignAsset;
use App\Models\DesignOrder;
use App\Models\Invoice;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Models\Run;
use App\Models\SupportThread;
use Illuminate\Support\Facades\DB;

/** Shapes models into the props the business-portal React pages consume. Money is always integer cents. */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function campaign(Campaign $c, bool $withAreas = true): array
    {
        $pickup = app(CampaignService::class)->pickupLngLat($c);

        return [
            'id' => $c->id,
            'type' => 'campaign',
            'reference' => $c->reference,
            'name' => $c->name,
            'status' => $c->status,
            'step' => $c->step,
            'include_houses' => $c->include_houses,
            'include_units' => $c->include_units,
            'coverage' => $c->coverage,
            'material' => $c->material,
            'pickup_address' => $c->pickup_address,
            'pickup' => $pickup ? [$pickup['lng'], $pickup['lat']] : null,
            'available_from' => $c->available_from?->toDateString(),
            'notes' => $c->notes,
            'promo_code' => $c->promo_code,
            'houses' => $c->houses,
            'units' => $c->units,
            'letterboxes' => $c->letterboxes,
            'distance_km' => (float) $c->distance_km,
            'subtotal_cents' => $c->subtotal_cents,
            'gst_cents' => $c->gst_cents,
            'total_cents' => $c->total_cents,
            'pricing' => $c->pricing,
            'print_order_id' => $c->print_order_id,
            'rating' => $c->rating,
            'rating_comment' => $c->rating_comment,
            'area_count' => $withAreas ? null : ($c->areas_count ?? null),
            'areas' => $withAreas ? self::areas($c) : [],
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'paid_at' => $c->paid_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function areas(Campaign $c): array
    {
        return $c->areas()->withGeoJson()->get()->map(fn (CampaignArea $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'position' => $a->position,
            'draw_mode' => $a->draw_mode,
            'suburb_id' => $a->suburb_id,
            'geometry' => $a->geoJson(),
            'houses' => $a->houses,
            'units' => $a->units,
            'total' => $a->houses + $a->units,
            'distance_km' => (float) $a->distance_km,
            'fee_cents' => $a->fee_cents,
            'estimated' => true,
        ])->values()->all();
    }

    /** Lightweight campaign card for lists (drafts, re-runs, hub). */
    public static function campaignCard(Campaign $c): array
    {
        $polys = $c->relationLoaded('areas') ? $c->areas : $c->areas()->withGeoJson()->get();

        return [
            'id' => $c->id,
            'type' => 'campaign',
            'reference' => $c->reference,
            'name' => $c->name,
            'status' => $c->status,
            'step' => $c->step,
            'letterboxes' => $c->letterboxes,
            'houses' => $c->houses,
            'units' => $c->units,
            'total_cents' => $c->total_cents,
            'area_count' => $polys->count(),
            'polygons' => $polys->map(fn (CampaignArea $a) => $a->geoJson())->filter()->values()->all(),
            'updated_at' => $c->updated_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'submitted_at' => $c->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function printOrder(PrintOrder $o): array
    {
        $o->loadMissing('product', 'artworks');

        return [
            'id' => $o->id,
            'type' => 'print',
            'reference' => $o->reference,
            'name' => ($o->product?->name ?? 'Print order').($o->quantity ? ' × '.number_format($o->quantity) : ''),
            'status' => $o->status,
            'step' => $o->step,
            'product' => $o->product ? self::product($o->product) : null,
            'gsm' => $o->gsm,
            'finish' => $o->finish,
            'sides' => $o->sides,
            'orientation' => $o->orientation,
            'quantity' => $o->quantity,
            'addons' => (array) (($o->pricing['meta']['addons'] ?? null) ?: []),
            'subtotal_cents' => $o->subtotal_cents,
            'gst_cents' => $o->gst_cents,
            'total_cents' => $o->total_cents,
            'pricing' => $o->pricing,
            'promo_code' => $o->promo_code,
            'delivery_name' => $o->delivery_name,
            'delivery_address' => $o->delivery_address,
            'delivery_phone' => $o->delivery_phone,
            'earliest_delivery' => $o->earliest_delivery?->toDateString(),
            'also_distribute' => $o->also_distribute,
            'campaign_id' => $o->campaign_id,
            'right_to_print' => $o->right_to_print,
            'tracking_number' => $o->tracking_number,
            'rating' => $o->rating,
            'rating_comment' => $o->rating_comment,
            'artworks' => $o->artworks->map(fn (Artwork $a) => self::artwork($o, $a))->values()->all(),
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
            'completed_at' => $o->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function artwork(PrintOrder $o, Artwork $a): array
    {
        return [
            'id' => $a->id,
            'side' => $a->side,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size_bytes' => $a->size_bytes,
            'width_px' => $a->width_px,
            'height_px' => $a->height_px,
            'status' => $a->status,
            'review_note' => $a->review_note,
            'url' => route('app.print.artworks.show', [$o, $a]),
        ];
    }

    /** @return array<string, mixed> */
    public static function product(PrintProduct $p): array
    {
        return $p->only('id', 'slug', 'name', 'description', 'category', 'size_label', 'width_mm', 'height_mm', 'bleed_mm', 'from_price_cents', 'gsm_options', 'finish_options', 'quantity_options', 'distributable', 'distribution_material', 'turnaround_days')
            + ['sides_options' => $p->sides(), 'image_url' => $p->imageUrl(), 'category_name' => $p->printCategory?->name, 'category_icon' => $p->printCategory?->icon];
    }

    /** @return array<string, mixed> */
    public static function designOrder(DesignOrder $o, bool $staff = false): array
    {
        $o->loadMissing('product', 'assets', 'designer:id,name');
        $files = $o->assets->map(fn (DesignAsset $a) => self::designAsset($o, $a, $staff))->values();

        return [
            'id' => $o->id,
            'type' => 'design',
            'reference' => $o->reference,
            'name' => $o->displayName(),
            'type_label' => $o->typeLabel(),
            'type_icon' => (string) (app(PricingService::class)->designCard()['types'][$o->design_type]['icon'] ?? 'palette'),
            'package_label' => $o->packageLabel(),
            'status' => $o->status,
            'step' => $o->step,
            'design_type' => $o->design_type,
            'package' => $o->package,
            'size' => $o->size,
            'print_product_id' => $o->print_product_id,
            'product' => $o->product ? self::product($o->product) : null,
            'sides' => (int) $o->sides,
            'headline' => $o->headline,
            'key_message' => $o->key_message,
            'call_to_action' => $o->call_to_action,
            'contact_details' => $o->contact_details,
            'brand_colours' => $o->brand_colours,
            'style_notes' => $o->style_notes,
            'target_audience' => $o->target_audience,
            'due_date' => $o->due_date?->toDateString(),
            'revisions_included' => $o->revisions_included,
            'revisions_used' => $o->revisions_used,
            'revisions_unlimited' => $o->revisions_included >= DesignOrder::UNLIMITED_REVISIONS,
            'extra_revisions' => $o->extraRevisions(),
            'addons' => DesignService::addons($o),
            'subtotal_cents' => $o->subtotal_cents,
            'gst_cents' => $o->gst_cents,
            'total_cents' => $o->total_cents,
            'pricing' => $o->pricing,
            'promo_code' => $o->promo_code,
            'designer' => $o->designer ? ['id' => $o->designer->id, 'name' => $o->designer->name] : null,
            'print_order_id' => $o->print_order_id,
            'brief_files' => $files->where('kind', 'brief_upload')->values()->all(),
            'proofs' => $files->where('kind', 'proof')->sortByDesc('version')->values()->all(),
            'finals' => $files->where('kind', 'final')->values()->all(),
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
            'approved_at' => $o->approved_at?->toIso8601String(),
            'completed_at' => $o->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function designAsset(DesignOrder $o, DesignAsset $a, bool $staff = false): array
    {
        $url = $staff ? route('admin.design-orders.asset', [$o, $a]) : route('app.design.assets.show', [$o, $a]);

        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'version' => $a->version,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size_bytes' => $a->size_bytes,
            'width' => $a->width,
            'height' => $a->height,
            'note' => $a->note,
            'is_image' => $a->isImage(),
            'url' => $url,
            'download_url' => $url.'?download=1',
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** Design services, packages and add-ons from the active rate card (money in cents ex GST). */
    public static function designCatalogue(): array
    {
        $card = app(PricingService::class)->designCard();

        return [
            'types' => collect($card['types'])->map(fn ($t, $k) => [
                'value' => (string) $k,
                'label' => (string) $t['label'],
                'description' => (string) ($t['description'] ?? ''),
                'icon' => (string) ($t['icon'] ?? 'palette'),
                'print_categories' => array_values((array) ($t['print_categories'] ?? [])),
                'sides_option' => (bool) ($t['sides_option'] ?? false),
                'prices' => collect($t['prices'])->map(fn ($v) => (int) round((float) $v * 100))->all(),
                'from_cents' => (int) round(min(array_map('floatval', $t['prices'])) * 100),
            ])->values()->all(),
            'packages' => collect($card['packages'])->map(fn ($p, $k) => [
                'value' => (string) $k,
                'label' => (string) $p['label'],
                'description' => (string) ($p['description'] ?? ''),
                'revisions' => (int) $p['revisions'],
                'unlimited' => (int) $p['revisions'] >= DesignOrder::UNLIMITED_REVISIONS,
                'turnaround_days' => (int) $p['turnaround_days'],
            ])->values()->all(),
            'addons' => collect($card['addons'])->map(fn ($a, $k) => [
                'value' => (string) $k,
                'label' => (string) $a['label'],
                'pct' => isset($a['pct']) ? (float) $a['pct'] : null,
                'fixed_cents' => isset($a['fixed']) ? (int) round((float) $a['fixed'] * 100) : null,
            ])->values()->all(),
            'double_sided_pct' => (float) ($card['double_sided_pct'] ?? 0),
            'extra_revision_cents' => (int) round((float) ($card['addons']['extra_revision']['fixed'] ?? 0) * 100),
            'gst_rate' => (float) (PricingFactory::rateCard()['gst_rate'] ?? 0.10),
        ];
    }

    /** Material + coverage option lists from the active rate card (prices incl. GST per item, in cents). */
    public static function distributionOptions(Campaign $c): array
    {
        $card = PricingFactory::rateCard();
        $gst = 1 + (float) ($card['gst_rate'] ?? 0.10);
        $d = $card['distribution'];
        $add = (float) ($d['materials'][$c->material]['add'] ?? 0);
        $baseA5 = (float) ($d['materials']['a5']['add'] ?? 0);

        return [
            'materials' => collect($d['materials'])->map(fn ($m, $k) => [
                'value' => $k,
                'label' => $m['label'],
                'add_cents' => round(((float) $m['add'] - $baseA5) * 100, 2),
            ])->values()->all(),
            'coverages' => collect($d['coverage'])->map(fn ($cv, $k) => [
                'value' => $k,
                'label' => $cv['label'],
                'per_item_incl_gst_cents' => round(((float) $cv['per_item'] + $add) * $gst * 100, 2),
                'letterboxes' => match ($k) {
                    'houses_only' => $c->houses,
                    'units_only' => $c->units,
                    default => $c->houses + $c->units,
                },
            ])->values()->all(),
            'minimum_fee_cents' => (int) round((float) ($d['minimum_fee'] ?? 0) * $gst * 100),
        ];
    }

    /** @return array<string, mixed> */
    public static function billing(Business $b): array
    {
        return ['company' => $b->name, 'abn' => $b->abn, 'address' => $b->address];
    }

    /** @return array<string, mixed> */
    public static function paymentContext(Business $b): array
    {
        $gateway = app(PaymentGatewayManager::class)->driver();

        return [
            'saved_cards' => $b->savedCards()->where('gateway', $gateway->name())->orderByDesc('is_default')->latest()->get()->map(fn ($c) => $c->only('id', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name', 'is_default'))->all(),
            'credits_cents' => $b->creditBalanceCents(),
            'can_pay' => $b->canPay(),
            'business_status' => $b->status,
            'billing' => self::billing($b),
            'gateway' => $gateway->clientConfig(),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'subtotal_cents' => $i->subtotal_cents,
            'gst_cents' => $i->gst_cents,
            'total_cents' => $i->total_cents,
            'credits_applied_cents' => $i->credits_applied_cents,
            'issued_at' => $i->issued_at?->toIso8601String(),
            'pdf_url' => route('app.invoices.pdf', $i),
        ];
    }

    /** @return array<string, mixed> */
    public static function run(Run $r, bool $withTrack = false): array
    {
        $geom = $r->geoJson();
        $out = [
            'id' => $r->id,
            'reference' => $r->reference,
            'name' => $r->name,
            'status' => $r->status,
            'flyers' => $r->flyers,
            'houses' => $r->houses,
            'units' => $r->units,
            'deadline' => $r->deadline?->toDateString(),
            'coverage_pct' => $r->coverage_pct,
            'geometry' => $geom,
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'campaign' => $r->relationLoaded('campaign') && $r->campaign ? ['id' => $r->campaign->id, 'name' => $r->campaign->name, 'reference' => $r->campaign->reference] : null,
            'gps_count' => $r->gps_points_count ?? null,
        ];
        if ($withTrack) {
            $out['track'] = array_map(fn ($p) => [(float) $p->lng, (float) $p->lat], DB::select(
                'SELECT ST_X(geom) AS lng, ST_Y(geom) AS lat FROM run_gps_points WHERE run_id = ? ORDER BY recorded_at', [$r->id]
            ));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function thread(?SupportThread $t): ?array
    {
        if (! $t) {
            return null;
        }
        $t->loadMissing('messages.user');

        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'channel' => $t->channel,
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'messages' => $t->messages->map(fn ($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'from_staff' => $m->from_staff,
                'author' => $m->from_staff ? 'EPFD team' : ($m->user?->name ?? 'You'),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
