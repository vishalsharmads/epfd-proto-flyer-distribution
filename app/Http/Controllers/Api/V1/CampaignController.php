<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\CampaignService;
use App\Domain\Payments\CheckoutService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PayRequest;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\PaymentResource;
use App\Models\Campaign;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * @tags Campaigns
 */
class CampaignController extends Controller
{
    use ScopesToBusiness;

    public function __construct(private readonly CampaignService $campaigns) {}

    /** List your campaigns (newest first). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', Campaign::STATUSES)]]);

        return CampaignResource::collection(
            $this->business($request)->campaigns()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')
                ->paginate($this->perPage($request))
        );
    }

    /** Create a draft campaign. Pickup defaults to the business address location. */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'include_houses' => ['boolean'],
            'include_units' => ['boolean'],
            'coverage' => ['nullable', 'in:houses_units,houses_only,units_only'],
            'material' => ['nullable', 'string', 'max:40'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'pickup_lng' => ['nullable', 'required_with:pickup_lat', 'numeric', 'between:112,155'],
            'pickup_lat' => ['nullable', 'required_with:pickup_lng', 'numeric', 'between:-45,-9'],
            'available_from' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['name']) && ! $this->campaigns->nameAvailable($business, $data['name'])) {
            throw new DomainProblem('validation_failed', 'You already have a campaign with this name.', 422, 'Validation failed', ['errors' => ['name' => ['You already have a campaign with this name.']]]);
        }
        $campaign = $this->campaigns->createDraft($business, $request->user(), array_filter($data, fn ($v) => $v !== null));

        return response()->json(['data' => CampaignResource::make($campaign->fresh()->load('areas'))], 201);
    }

    /** Show a campaign with its areas. */
    public function show(Request $request, int $campaign): JsonResponse
    {
        return response()->json(['data' => CampaignResource::make($this->find($request, $campaign)->load('areas'))]);
    }

    /** Update a draft campaign (409 `conflict` once submitted). Changing toggles/pickup re-estimates areas. */
    public function update(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'step' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'include_houses' => ['sometimes', 'boolean'],
            'include_units' => ['sometimes', 'boolean'],
            'coverage' => ['sometimes', 'in:houses_units,houses_only,units_only'],
            'material' => ['sometimes', 'string', 'max:40'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_lng' => ['sometimes', 'required_with:pickup_lat', 'numeric', 'between:112,155'],
            'pickup_lat' => ['sometimes', 'required_with:pickup_lng', 'numeric', 'between:-45,-9'],
            'available_from' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $model = $this->campaigns->update($model, $data);

        return response()->json(['data' => CampaignResource::make($model->load('areas'))]);
    }

    /** Delete a draft campaign. */
    public function destroy(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        if (! $model->isDraft()) {
            throw new DomainProblem('conflict', 'Only draft campaigns can be deleted.', 409, 'Conflict');
        }
        $this->campaigns->deleteDraft($model);

        return response()->json(null, 204);
    }

    /**
     * Replace all areas of a draft campaign.
     *
     * Every polygon is validated (`polygon_*` error codes) and letterboxes are counted server-side;
     * client counts are never trusted. Coordinates are GeoJSON [lng, lat] (GDA2020 / WGS84).
     */
    public function syncAreas(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        if (! $model->isDraft()) {
            throw new DomainProblem('conflict', 'Only draft campaigns can be edited.', 409, 'Conflict');
        }
        $data = $request->validate([
            'areas' => ['present', 'array', 'max:50'],
            'areas.*.name' => ['nullable', 'string', 'max:120'],
            'areas.*.draw_mode' => ['nullable', 'in:pins,trace,suburb'],
            'areas.*.suburb_id' => ['nullable', 'integer'],
            'areas.*.geometry' => ['required', 'array'],
            'areas.*.geometry.type' => ['required', 'in:Polygon'],
            'areas.*.geometry.coordinates' => ['required', 'array'],
        ]);
        $model = $this->campaigns->syncAreas($model, $data['areas']);

        return response()->json(['data' => CampaignResource::make($model->load('areas'))]);
    }

    /**
     * Check readiness for payment. Returns 200 `{ready: true}` or 422 problem+json with `errors`.
     */
    public function submit(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        if (! $model->isDraft()) {
            throw new DomainProblem('conflict', 'This campaign has already been submitted.', 409, 'Conflict');
        }
        $model = $this->campaigns->prepareForPayment($model);

        return response()->json(['data' => [
            'ready' => true,
            'can_pay' => $model->business->canPay(),
            'total_cents' => $model->total_cents,
            'letterboxes' => $model->letterboxes,
        ]]);
    }

    /**
     * Pay for a campaign (credits first, then card). Send an `Idempotency-Key` header to make retries safe.
     * Declined cards return 402 `payment_declined`; pending businesses get 403 `business_not_approved`.
     */
    public function pay(PayRequest $request, int $campaign, CheckoutService $checkout): JsonResponse
    {
        $model = $this->find($request, $campaign);
        if ($model->isDraft()) {
            $model = $this->campaigns->prepareForPayment($model);
        }
        $payment = $checkout->pay($model, $request->user(), $request->checkoutInput());

        return response()->json(['data' => [
            'payment' => PaymentResource::make($payment->fresh()),
            'campaign' => CampaignResource::make($model->fresh()),
            'invoice_id' => $payment->invoice?->id,
        ]], 201);
    }

    /** Delivery tracking: runs with status, latest GPS fix, coverage and GeoJSON tracks. */
    public function tracking(Request $request, int $campaign): JsonResponse
    {
        $model = $this->find($request, $campaign);
        $runs = $model->runs()->withGeoJson()->orderBy('id')->get();
        $ids = $runs->pluck('id')->all();
        $tracks = $latest = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            foreach (DB::select("SELECT run_id, ST_AsGeoJSON(ST_MakeLine(geom ORDER BY recorded_at), 6) AS line, count(*) AS n FROM run_gps_points WHERE run_id IN ({$in}) GROUP BY run_id") as $r) {
                $tracks[$r->run_id] = ['geometry' => json_decode((string) $r->line, true), 'points' => (int) $r->n];
            }
            foreach (DB::select("SELECT DISTINCT ON (run_id) run_id, ST_X(geom) AS lng, ST_Y(geom) AS lat, accuracy_m, recorded_at FROM run_gps_points WHERE run_id IN ({$in}) ORDER BY run_id, recorded_at DESC") as $r) {
                $latest[$r->run_id] = ['lng' => (float) $r->lng, 'lat' => (float) $r->lat, 'accuracy_m' => $r->accuracy_m !== null ? (float) $r->accuracy_m : null, 'recorded_at' => $r->recorded_at];
            }
        }

        return response()->json(['data' => [
            'campaign_id' => $model->id,
            'status' => $model->status,
            'timeline' => Campaign::TIMELINE,
            'runs' => $runs->map(fn (Run $run) => [
                'id' => $run->id,
                'reference' => $run->reference,
                'name' => $run->name,
                'status' => $run->status,
                'coverage_pct' => $run->coverage_pct,
                'latest_point' => $latest[$run->id] ?? null,
                'area' => $run->geoJson(),
                'track' => [
                    'type' => 'Feature',
                    'geometry' => $tracks[$run->id]['geometry'] ?? null,
                    'properties' => ['run_id' => $run->id, 'points' => $tracks[$run->id]['points'] ?? 0],
                ],
            ])->values(),
        ]]);
    }

    private function find(Request $request, int $id): Campaign
    {
        return $this->business($request)->campaigns()->findOrFail($id);
    }
}
