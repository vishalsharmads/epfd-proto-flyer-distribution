<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Runs\RunService;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\RunResource;
use App\Models\Campaign;
use App\Models\Run;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Campaign operations and distribution runs.
 *
 * Run lifecycle is delegated to App\Domain\Runs\RunService (shared with the admin panel).
 *
 * @tags Admin · Campaigns & runs
 */
class CampaignController extends Controller
{
    use ScopesToBusiness;

    public function __construct(private readonly RunService $runs) {}

    /** All campaigns (filter `status`, `business_id`). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', Campaign::STATUSES)], 'business_id' => ['nullable', 'integer']]);

        return CampaignResource::collection(
            Campaign::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('business_id'), fn ($q, $b) => $q->where('business_id', $b))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Campaign detail with areas and runs. */
    public function show(int $campaign): JsonResponse
    {
        $c = Campaign::with('areas')->findOrFail($campaign);

        return response()->json(['data' => [
            ...CampaignResource::make($c)->resolve(),
            'runs' => RunResource::collection($c->runs()->withGeoJson()->orderBy('id')->get())->resolve(),
        ]]);
    }

    /**
     * Create draft runs for a paid campaign: `from_areas: true` makes one run per campaign area,
     * or pass `runs: [{name, geometry, deadline?}]` to split by custom polygons (re-counted server-side).
     * Existing draft runs are replaced.
     */
    public function createRuns(Request $request, int $campaign): JsonResponse
    {
        $c = Campaign::findOrFail($campaign);
        $data = $request->validate([
            'from_areas' => ['boolean', 'required_without:runs'],
            'runs' => ['array', 'required_without:from_areas', 'max:100'],
            'runs.*.name' => ['nullable', 'string', 'max:120'],
            'runs.*.geometry' => ['required', 'array'],
            'runs.*.geometry.type' => ['required', 'in:Polygon'],
            'runs.*.geometry.coordinates' => ['required', 'array'],
            'runs.*.deadline' => ['nullable', 'date'],
            'runs.*.pickup_point_id' => ['nullable', 'integer'],
        ]);
        $created = ! empty($data['runs']) ? $this->runs->split($c, $data['runs']) : $this->runs->onePerArea($c);

        return response()->json(['data' => RunResource::collection(Run::withGeoJson()->whereIn('id', $created->pluck('id'))->orderBy('id')->get())], 201);
    }

    /** Publish a draft run to the distributor job board. The campaign rolls up to `runs_published`. */
    public function publishRun(int $run): JsonResponse
    {
        $r = $this->runs->transition(Run::findOrFail($run), 'published');

        return response()->json(['data' => RunResource::make(Run::withGeoJson()->findOrFail($r->id))]);
    }

    /** Assign a run directly to a distributor (→ allocated). */
    public function assignRun(Request $request, int $run): JsonResponse
    {
        $data = $request->validate(['distributor_id' => ['required', 'integer']]);
        $r = $this->runs->assign(Run::findOrFail($run), User::findOrFail($data['distributor_id']));

        return response()->json(['data' => RunResource::make(Run::withGeoJson()->findOrFail($r->id))]);
    }

    /** Move a run to another status (validated transitions; completing computes coverage). */
    public function runStatus(Request $request, int $run): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:'.implode(',', Run::STATUSES)]]);
        $r = $this->runs->transition(Run::findOrFail($run), $data['status']);
        if ($r->status === 'completed' && $r->coverage_pct === null) {
            $this->runs->computeCoverage($r);
        }

        return response()->json(['data' => RunResource::make(Run::withGeoJson()->findOrFail($r->id))]);
    }
}
