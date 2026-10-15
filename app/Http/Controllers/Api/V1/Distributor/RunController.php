<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Domain\Runs\RunService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\RunResource;
use App\Models\Run;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Distributor app stubs. Requires a user with `is_distributor = true`.
 *
 * @tags Distributor (stub)
 */
class RunController extends Controller
{
    use ScopesToBusiness;

    /** Coverage counts a letterbox as delivered when the GPS track passes within this distance. */
    public const COVERAGE_RADIUS_M = 25;

    /** Published runs, nearest first when `lat`/`lng` are given (`radius_km` default 30). */
    public function available(Request $request): AnonymousResourceCollection
    {
        $this->ensureDistributor($request);
        $data = $request->validate([
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:112,155'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-45,-9'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:200'],
        ]);
        $q = Run::query()->withGeoJson()->where('status', 'published');
        if (isset($data['lng'], $data['lat'])) {
            $pt = sprintf('ST_SetSRID(ST_MakePoint(%.8F, %.8F), 7844)', $data['lng'], $data['lat']);
            $q->whereRaw("ST_DWithin(geom::geography, {$pt}::geography, ?)", [(float) ($data['radius_km'] ?? 30) * 1000])
                ->orderByRaw("geom <-> {$pt}");
        } else {
            $q->orderBy('deadline');
        }

        return RunResource::collection($q->paginate($this->perPage($request)));
    }

    /** Claim a published run (→ allocated to you). 409 if already taken. */
    public function claim(Request $request, int $run): JsonResponse
    {
        $user = $this->ensureDistributor($request);
        $updated = Run::whereKey($run)->where('status', 'published')
            ->update(['status' => 'allocated', 'distributor_id' => $user->id, 'allocated_at' => now(), 'updated_at' => now()]);
        if (! $updated) {
            Run::findOrFail($run);
            throw new DomainProblem('conflict', 'This run is no longer available.', 409, 'Conflict');
        }
        $model = Run::withGeoJson()->findOrFail($run);
        app(RunService::class)->rollUp($model->campaign);
        Audit::log('run.claimed', $model);

        return response()->json(['data' => RunResource::make($model)]);
    }

    /** Upload a batch of GPS fixes (max 1000). The first batch moves the run to `in_delivery`. */
    public function gps(Request $request, int $run): JsonResponse
    {
        $model = $this->ownRun($request, $run, ['allocated', 'collected', 'in_delivery']);
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:1000'],
            'points.*.lng' => ['required', 'numeric', 'between:112,155'],
            'points.*.lat' => ['required', 'numeric', 'between:-45,-9'],
            'points.*.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'points.*.speed_mps' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'points.*.recorded_at' => ['required', 'date'],
        ]);
        DB::transaction(function () use ($model, $data) {
            foreach (array_chunk($data['points'], 200) as $chunk) {
                $values = $bind = [];
                foreach ($chunk as $p) {
                    $values[] = '(?, ST_SetSRID(ST_MakePoint(?, ?), 7844), ?, ?, ?)';
                    array_push($bind, $model->id, (float) $p['lng'], (float) $p['lat'], $p['accuracy_m'] ?? null, $p['speed_mps'] ?? null, Carbon::parse($p['recorded_at'])->utc()->toDateTimeString());
                }
                DB::insert('INSERT INTO run_gps_points (run_id, geom, accuracy_m, speed_mps, recorded_at) VALUES '.implode(',', $values), $bind);
            }
            if ($model->status !== 'in_delivery') {
                $model->forceFill(['status' => 'in_delivery', 'started_at' => $model->started_at ?? now()])->save();
                app(RunService::class)->rollUp($model->campaign);
            }
        });

        return response()->json(['data' => ['accepted' => count($data['points']), 'run_status' => $model->status]], 202);
    }

    /**
     * Finish a run (→ completed). Computes `coverage_pct` with PostGIS: the share of letterbox address
     * points inside the run polygon that lie within 25 m of the GPS track (measured in the MGA2020 zone).
     */
    public function submit(Request $request, int $run): JsonResponse
    {
        $model = $this->ownRun($request, $run, ['allocated', 'collected', 'in_delivery']);
        $cov = self::coverage($model->id);
        $model->forceFill(['status' => 'completed', 'completed_at' => now(), 'coverage_pct' => $cov['coverage_pct']])->save();
        app(RunService::class)->rollUp($model->campaign);
        Audit::log('run.completed', $model, $cov);

        return response()->json(['data' => [...RunResource::make(Run::withGeoJson()->findOrFail($model->id))->resolve($request), 'coverage' => $cov]]);
    }

    /** @return array{letterboxes:int, covered:int, coverage_pct:float|null, gps_points:int} */
    public static function coverage(int $runId): array
    {
        return app(RunService::class)->coverageBreakdown(Run::findOrFail($runId));
    }

    private function ensureDistributor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->is_distributor, 403, 'Distributor access only.');

        return $user;
    }

    /** @param list<string> $statuses */
    private function ownRun(Request $request, int $id, array $statuses): Run
    {
        $user = $this->ensureDistributor($request);
        $run = Run::where('distributor_id', $user->id)->findOrFail($id);
        if (! in_array($run->status, $statuses, true)) {
            throw new DomainProblem('conflict', "Run is {$run->status}.", 409, 'Conflict');
        }

        return $run;
    }
}
