<?php

declare(strict_types=1);

namespace App\Domain\Runs;

use App\Domain\Areas\LetterboxEstimator;
use App\Domain\Gnaf\AddressClassifier;
use App\Domain\Pricing\PricingFactory;
use App\Exceptions\DomainProblem;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\Run;
use App\Models\User;
use App\Support\Audit;
use App\Support\Geo;
use App\Support\Reference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Distribution runs: split a campaign into walkable runs, publish/assign them to distributors, advance
 * their status (rolling the campaign status up), and simulate a GPS track for demo/QA.
 *
 * Status flow per run: draft → published → allocated → collected → in_delivery → completed | failed.
 * Campaign status = the least-advanced run (failed runs count as finished); completed when all are done.
 */
final class RunService
{
    /** Progress order of run statuses → campaign status they roll up to. */
    public const ROLLUP = [
        'draft' => 'submitted',
        'published' => 'runs_published',
        'allocated' => 'allocated',
        'collected' => 'collected',
        'in_delivery' => 'in_delivery',
        'completed' => 'completed',
    ];

    /** Allowed manual transitions. */
    public const TRANSITIONS = [
        'draft' => ['published', 'allocated'],
        'published' => ['allocated', 'draft'],
        'allocated' => ['collected', 'published'],
        'collected' => ['in_delivery'],
        'in_delivery' => ['completed', 'failed'],
        'failed' => ['in_delivery'],
        'completed' => [],
    ];

    public const GPS_INTERVAL_S = 15;

    public const WALK_SPEED_MPS = 1.25;

    public const COVERAGE_RADIUS_M = 25;

    public function __construct(private readonly LetterboxEstimator $estimator) {}

    public function payPerItemCents(): float
    {
        return (float) (PricingFactory::rateCard()['distribution']['distributor_pay_per_item'] ?? 0.32) * 100;
    }

    /**
     * Replace the campaign's draft runs with the given polygons (server-side estimate, no pickup radius rule).
     *
     * @param  list<array{name?:string, geometry: array<string,mixed>, deadline?:string|null, pickup_point_id?:int|null}>  $runs
     * @return Collection<int, Run>
     */
    public function split(Campaign $campaign, array $runs): Collection
    {
        $this->assertSplittable($campaign);
        if ($runs === []) {
            throw new DomainProblem('validation_failed', 'Draw at least one run.', 422);
        }

        $created = DB::transaction(function () use ($campaign, $runs) {
            $campaign->runs()->where('status', 'draft')->delete();
            $out = collect();
            foreach (array_values($runs) as $i => $r) {
                $out->push($this->createRun($campaign, $r['geometry'], trim((string) ($r['name'] ?? '')) ?: 'Run '.($i + 1), null, $r));
            }

            return $out;
        });
        Audit::log('campaign.runs_split', $campaign, ['runs' => $created->pluck('reference')->all()]);

        return $created;
    }

    /** @return Collection<int, Run> */
    public function onePerArea(Campaign $campaign): Collection
    {
        $this->assertSplittable($campaign);
        $areas = $campaign->areas()->withGeoJson()->get();
        if ($areas->isEmpty()) {
            throw new DomainProblem('validation_failed', 'This campaign has no areas.', 422);
        }
        $created = DB::transaction(function () use ($campaign, $areas) {
            $campaign->runs()->where('status', 'draft')->delete();

            return $areas->map(fn (CampaignArea $a) => $this->createRun($campaign, (array) $a->geoJson(), $a->name, $a->id));
        });
        Audit::log('campaign.runs_created', $campaign, ['runs' => $created->pluck('reference')->all(), 'mode' => 'one_per_area']);

        return $created;
    }

    /** @param array<string, mixed> $attrs flyers, pay_cents, deadline, pickup_point_id, name */
    public function update(Run $run, array $attrs): Run
    {
        $before = $run->only(array_keys($attrs));
        $run->fill(array_intersect_key($attrs, array_flip(['name', 'flyers', 'pay_cents', 'deadline', 'pickup_point_id'])))->save();
        Audit::log('run.updated', $run, ['before' => $before, 'after' => $run->only(array_keys($attrs))]);

        return $run;
    }

    public function delete(Run $run): void
    {
        if (! in_array($run->status, ['draft', 'published'], true)) {
            throw new DomainProblem('invalid_state', 'Only draft or published runs can be deleted.', 409);
        }
        Audit::log('run.deleted', $run->campaign, ['run' => $run->reference]);
        $run->delete();
        $this->rollUp($run->campaign);
    }

    /** Publish every draft run of the campaign to the distributor job board. */
    public function publish(Campaign $campaign): int
    {
        $n = $campaign->runs()->where('status', 'draft')->update(['status' => 'published', 'published_at' => now()]);
        if ($n === 0) {
            throw new DomainProblem('invalid_state', 'There are no draft runs to publish.', 409);
        }
        Audit::log('campaign.runs_published', $campaign, ['count' => $n]);
        $this->rollUp($campaign);

        return $n;
    }

    public function assign(Run $run, User $distributor): Run
    {
        if (! $distributor->is_distributor) {
            throw new DomainProblem('validation_failed', 'That user is not a distributor.', 422);
        }
        if (! in_array($run->status, ['draft', 'published', 'allocated'], true)) {
            throw new DomainProblem('invalid_state', 'This run is already under way.', 409);
        }
        $run->forceFill([
            'distributor_id' => $distributor->id, 'status' => 'allocated', 'allocated_at' => now(),
            'published_at' => $run->published_at ?? now(),
        ])->save();
        Audit::log('run.assigned', $run, ['distributor_id' => $distributor->id, 'distributor' => $distributor->name]);
        $this->rollUp($run->campaign);

        return $run;
    }

    public function transition(Run $run, string $to): Run
    {
        $allowed = self::TRANSITIONS[$run->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new DomainProblem('invalid_state', "A run can't move from {$run->status} to {$to}.", 409);
        }
        if ($to === 'allocated' && ! $run->distributor_id) {
            throw new DomainProblem('validation_failed', 'Assign a distributor first.', 422);
        }
        $from = $run->status;
        $stamp = ['published' => 'published_at', 'allocated' => 'allocated_at', 'collected' => 'collected_at', 'in_delivery' => 'started_at', 'completed' => 'completed_at'][$to] ?? null;
        $run->status = $to;
        if ($stamp) {
            $run->{$stamp} = now();
        }
        $run->save();
        Audit::log('run.status_changed', $run, ['from' => $from, 'to' => $to]);
        $this->rollUp($run->campaign);

        return $run;
    }

    /** Campaign status = least-advanced run; completed when every run is completed/failed. */
    public function rollUp(Campaign $campaign): Campaign
    {
        $campaign->refresh();
        if (in_array($campaign->status, ['draft', 'cancelled'], true)) {
            return $campaign;
        }
        $statuses = $campaign->runs()->pluck('status');
        $order = array_keys(self::ROLLUP);
        if ($statuses->isEmpty()) {
            $target = 'submitted';
        } else {
            $min = $statuses->map(fn ($s) => $s === 'failed' ? count($order) - 1 : array_search($s, $order, true))->min();
            $target = self::ROLLUP[$order[$min]];
        }
        if ($target !== $campaign->status) {
            $from = $campaign->status;
            $campaign->forceFill(['status' => $target, 'completed_at' => $target === 'completed' ? now() : null])->save();
            Audit::log('campaign.status_changed', $campaign, ['from' => $from, 'to' => $target, 'via' => 'runs']);
        }

        return $campaign;
    }

    // ------------------------------------------------------------------ GPS simulation

    /**
     * The planned walking track for a run: G-NAF address points inside the run polygon, ordered by
     * street then by number (odd side then even side, snaking), interpolated to one fix every 15 s.
     *
     * @return list<array{0:float,1:float}> [lng, lat]
     */
    public function plannedTrack(Run $run): array
    {
        $rows = DB::select("
            WITH r AS (SELECT geom AS g FROM runs WHERE id = ?)
            SELECT DISTINCT ON (a.street_locality_pid, a.number_first, ST_X(a.geom), ST_Y(a.geom))
                   coalesce(a.street_locality_pid, a.street_name, '') AS street, a.number_first AS num,
                   ST_X(a.geom) AS lng, ST_Y(a.geom) AS lat
            FROM addresses a, r
            WHERE ST_Intersects(a.geom, r.g)
            ORDER BY a.street_locality_pid, a.number_first, ST_X(a.geom), ST_Y(a.geom)
            LIMIT 20000", [$run->id]);
        if ($rows === []) {
            return [];
        }

        // Group per street, walk odd numbers up then even numbers down (one side then the other).
        $streets = [];
        foreach ($rows as $r) {
            $streets[$r->street][] = $r;
        }
        $legs = [];
        foreach ($streets as $pts) {
            $odd = array_values(array_filter($pts, fn ($p) => $p->num === null || $p->num % 2 === 1));
            $even = array_values(array_filter($pts, fn ($p) => $p->num !== null && $p->num % 2 === 0));
            usort($odd, fn ($a, $b) => ($a->num ?? 0) <=> ($b->num ?? 0));
            usort($even, fn ($a, $b) => ($b->num ?? 0) <=> ($a->num ?? 0));
            $seq = array_map(fn ($p) => [(float) $p->lng, (float) $p->lat], array_merge($odd, $even));
            // de-duplicate identical consecutive geocodes (unit blocks)
            $legs[] = array_values(array_filter($seq, fn ($p, $i) => $i === 0 || $p !== $seq[$i - 1], ARRAY_FILTER_USE_BOTH));
        }

        // Nearest-neighbour ordering of streets from the first one so the walker doesn't zig-zag.
        $ordered = [array_shift($legs)];
        while ($legs !== []) {
            $end = end($ordered)[count(end($ordered)) - 1];
            $best = 0;
            $bestD = INF;
            $flip = false;
            foreach ($legs as $i => $leg) {
                $d1 = self::metres($end, $leg[0]);
                $d2 = self::metres($end, $leg[count($leg) - 1]);
                if (min($d1, $d2) < $bestD) {
                    $bestD = min($d1, $d2);
                    $best = $i;
                    $flip = $d2 < $d1;
                }
            }
            $leg = $legs[$best];
            unset($legs[$best]);
            $legs = array_values($legs);
            $ordered[] = $flip ? array_reverse($leg) : $leg;
        }
        $path = array_merge(...$ordered);

        // Interpolate at walking speed: one fix every GPS_INTERVAL_S seconds.
        $step = self::WALK_SPEED_MPS * self::GPS_INTERVAL_S;
        $out = [$path[0]];
        $carry = 0.0;
        for ($i = 1, $n = count($path); $i < $n; $i++) {
            [$a, $b] = [$path[$i - 1], $path[$i]];
            $d = self::metres($a, $b);
            if ($d <= 0) {
                continue;
            }
            $pos = $step - $carry;
            while ($pos <= $d) {
                $t = $pos / $d;
                $out[] = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
                $pos += $step;
            }
            $carry = $d - ($pos - $step);
        }
        $out[] = $path[count($path) - 1];

        // Light GPS jitter (±3 m), deterministic per run.
        mt_srand($run->id);

        return array_map(fn ($p) => [
            round($p[0] + (mt_rand(-100, 100) / 100) * 3 / (111320 * cos(deg2rad($p[1]))), 7),
            round($p[1] + (mt_rand(-100, 100) / 100) * 3 / 110540, 7),
        ], $out);
    }

    /**
     * Generate the whole track at once (replacing existing points) and compute coverage.
     *
     * @return array{points:int, coverage_pct:float|null}
     */
    public function simulateGps(Run $run): array
    {
        if (! in_array($run->status, ['in_delivery', 'completed'], true)) {
            throw new DomainProblem('invalid_state', 'GPS can only be simulated for a run that is in delivery.', 409);
        }
        $track = $this->plannedTrack($run);
        if ($track === []) {
            throw new DomainProblem('validation_failed', 'No G-NAF address points inside this run.', 422);
        }
        $start = Carbon::parse($run->started_at ?? now());
        DB::transaction(function () use ($run, $track, $start) {
            $run->gpsPoints()->delete();
            foreach (array_chunk($track, 500, true) as $chunk) {
                $values = [];
                $bind = [];
                foreach ($chunk as $i => $p) {
                    $values[] = '(?, ST_SetSRID(ST_MakePoint(?, ?), 7844), ?, ?, ?)';
                    array_push($bind, $run->id, $p[0], $p[1], 5.0, self::WALK_SPEED_MPS, $start->copy()->addSeconds($i * self::GPS_INTERVAL_S));
                }
                DB::insert('INSERT INTO run_gps_points (run_id, geom, accuracy_m, speed_mps, recorded_at) VALUES '.implode(',', $values), $bind);
            }
        });
        $coverage = $this->computeCoverage($run);
        Audit::log('run.gps_simulated', $run, ['points' => count($track), 'coverage_pct' => $coverage]);

        return ['points' => count($track), 'coverage_pct' => $coverage];
    }

    /** Append one GPS fix (used by `runs:simulate-gps --live`). */
    public function appendPoint(Run $run, float $lng, float $lat): void
    {
        DB::insert('INSERT INTO run_gps_points (run_id, geom, accuracy_m, speed_mps, recorded_at) VALUES (?, ST_SetSRID(ST_MakePoint(?, ?), 7844), 5, ?, now())', [$run->id, $lng, $lat, self::WALK_SPEED_MPS]);
    }

    /** % of countable letterbox points inside the run within 25 m of the GPS track (metres in MGA2020). */
    /**
     * The single coverage formula (used by admin status changes AND the distributor submit API):
     * share of LETTERBOX-COUNTABLE address points (AddressClassifier rules: no commercial, no unit-building
     * primaries) inside the run polygon that lie within COVERAGE_RADIUS_M of the GPS track, measured in metres
     * in the run's MGA2020 zone.
     *
     * @return array{letterboxes:int, covered:int, coverage_pct:float|null, gps_points:int}
     */
    public function coverageBreakdown(Run $run): array
    {
        $countable = app(AddressClassifier::class)->countableSql('a');
        $r = DB::selectOne("
            WITH run AS (SELECT geom, epfd_mga_srid(geom) AS srid FROM runs WHERE id = ?),
                 track AS (
                    SELECT CASE WHEN count(*) >= 2 THEN ST_MakeLine(geom ORDER BY recorded_at, id) ELSE ST_Collect(geom) END AS g,
                           count(*) AS n FROM run_gps_points WHERE run_id = ?),
                 boxes AS (SELECT a.geom FROM addresses a, run WHERE ST_Intersects(a.geom, run.geom) AND {$countable})
            SELECT (SELECT count(*) FROM boxes) AS total,
                   (SELECT n FROM track) AS n,
                   (SELECT count(*) FROM boxes, track, run
                     WHERE track.g IS NOT NULL
                       AND ST_DWithin(ST_Transform(boxes.geom, run.srid), ST_Transform(track.g, run.srid), ?)) AS covered",
            [$run->id, $run->id, self::COVERAGE_RADIUS_M]);
        $total = (int) ($r->total ?? 0);
        $covered = (int) ($r->covered ?? 0);

        return [
            'letterboxes' => $total,
            'covered' => $covered,
            'coverage_pct' => $total > 0 && (int) ($r->n ?? 0) > 0 ? round($covered / $total * 100, 2) : null,
            'gps_points' => (int) ($r->n ?? 0),
        ];
    }

    public function computeCoverage(Run $run): ?float
    {
        $pct = $this->coverageBreakdown($run)['coverage_pct'];
        $run->forceFill(['coverage_pct' => $pct])->save();

        return $pct;
    }

    // ------------------------------------------------------------------ internals

    private function assertSplittable(Campaign $campaign): void
    {
        if (in_array($campaign->status, ['draft', 'cancelled', 'completed'], true)) {
            throw new DomainProblem('invalid_state', 'Runs can only be created for submitted campaigns.', 409);
        }
        if ($campaign->runs()->whereNotIn('status', ['draft'])->exists()) {
            throw new DomainProblem('invalid_state', 'Runs are already published. Unpublish them before re-splitting.', 409);
        }
    }

    /** @param array<string, mixed> $geometry */
    private function createRun(Campaign $campaign, array $geometry, string $name, ?int $areaId, array $extra = []): Run
    {
        $r = $this->estimator->estimate($geometry, (bool) $campaign->include_houses, (bool) $campaign->include_units, null);
        $est = $r['estimate'];
        $limit = (float) config('epfd.areas.max_walk_km');
        if ($est->distanceKm > $limit) {
            throw new DomainProblem('run_over_walk_limit', sprintf('%s is a %s km walk, over the %s km limit for one distributor. Split it into smaller runs.', $name, number_format($est->distanceKm, 1), rtrim(rtrim(number_format($limit, 1), '0'), '.')), 422);
        }
        $flyers = $est->houses + $est->units;
        $run = new Run([
            'campaign_id' => $campaign->id,
            'campaign_area_id' => $areaId,
            'reference' => Reference::make('RUN', 'runs'),
            'name' => mb_substr($name, 0, 120),
            'flyers' => $flyers,
            'houses' => $est->houses,
            'units' => $est->units,
            'distance_km' => $est->distanceKm,
            'pay_cents' => (int) round($flyers * $this->payPerItemCents()),
            'deadline' => ! empty($extra['deadline']) ? $extra['deadline'] : ($campaign->available_from ? Carbon::parse($campaign->available_from)->addDays(7) : now()->addDays(10))->toDateString(),
            'pickup_point_id' => $extra['pickup_point_id'] ?? $campaign->pickup_point_id,
            'status' => 'draft',
        ]);
        $run->setAttribute('geom', Geo::fromGeoJson($r['polygon']));
        $run->save();

        return $run;
    }

    /** @param array{0:float,1:float} $a @param array{0:float,1:float} $b */
    public static function metres(array $a, array $b): float
    {
        $lat = deg2rad(($a[1] + $b[1]) / 2);
        $dx = ($b[0] - $a[0]) * 111320 * cos($lat);
        $dy = ($b[1] - $a[1]) * 110540;

        return sqrt($dx * $dx + $dy * $dy);
    }
}
