<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Areas\GnafLetterboxSource;
use App\Domain\Gnaf\AddressClassifier;
use App\Support\Geo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the estimator performance target: a polygon containing ~5,000 letterboxes must be estimated
 * in under 1 s. Grows a square around each probe point until it holds ≥ target letterboxes, then times
 * uncached estimates and prints EXPLAIN (ANALYZE, BUFFERS) for the counting query.
 */
class GnafBenchmarkCommand extends Command
{
    protected $signature = 'gnaf:benchmark {--target=5000} {--runs=5} {--budget-ms=1000}';

    protected $description = 'Benchmark the letterbox estimator (target: 5,000-letterbox polygon < 1 s)';

    /** Probe points: suburbs with dense and sparse housing in the default states. */
    private const PROBES = [
        'Parramatta NSW' => [151.0034, -33.8150],
        'Rydalmere NSW' => [151.0330, -33.8130],
        'Bondi NSW' => [151.2650, -33.8910],
        'Penrith NSW' => [150.6940, -33.7510],
        'Richmond VIC' => [144.9990, -37.8230],
        'Werribee VIC' => [144.6600, -37.9000],
    ];

    public function handle(GnafLetterboxSource $source, AddressClassifier $classifier): int
    {
        $target = (int) $this->option('target');
        $runs = (int) $this->option('runs');
        $budget = (float) $this->option('budget-ms');
        $total = (int) DB::selectOne('SELECT count(*) AS n FROM addresses')->n;
        $this->info(sprintf('addresses table: %s rows. Target: %s letterboxes in < %d ms (uncached).', number_format($total), number_format($target), $budget));

        $rows = [];
        $worst = 0.0;
        $explainPolygon = null;
        $countable = $classifier->countableSql('a');
        foreach (self::PROBES as $name => [$lng, $lat]) {
            $polygon = $this->growSquare($lng, $lat, $target, $countable);
            if ($polygon === null) {
                $rows[] = [$name, '—', '—', '—', '—', 'no data loaded here'];

                continue;
            }
            $explainPolygon ??= $polygon;
            $times = [];
            $est = null;
            for ($i = 0; $i < $runs; $i++) {
                $est = $source->estimate($polygon, true, true);
                $times[] = $est->elapsedMs;
            }
            sort($times);
            $p50 = $times[intdiv(count($times), 2)];
            $max = max($times);
            $worst = max($worst, $max);
            $rows[] = [$name, number_format($est->total()), number_format($est->houses).' / '.number_format($est->units), $est->distanceKm.' km',
                sprintf('%.0f ms / %.0f ms', $p50, $max), $max < $budget ? 'PASS' : 'FAIL'];
        }
        $this->table(['Probe', 'Letterboxes', 'Houses / Units', 'Est. distance', 'p50 / max', 'Result'], $rows);

        if ($explainPolygon) {
            $this->info('EXPLAIN (ANALYZE, BUFFERS) — counting query (Parramatta probe):');
            $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$source->sql(true, true), [json_encode($explainPolygon), 250.0, 400.0]);
            foreach ($plan as $line) {
                $this->line('  '.$line->{'QUERY PLAN'});
            }
        }

        $ok = $worst > 0 && $worst < $budget;
        $ok ? $this->info(sprintf('RESULT: PASS — worst case %.0f ms < %d ms', $worst, $budget))
            : $this->error(sprintf('RESULT: FAIL — worst case %.0f ms', $worst));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed>|null */
    private function growSquare(float $lng, float $lat, int $target, string $countable): ?array
    {
        $half = 150.0; // metres
        for ($i = 0; $i < 20; $i++) {
            $poly = $this->square($lng, $lat, $half);
            $n = (int) DB::selectOne("SELECT count(*) AS n FROM addresses a WHERE ST_Intersects(a.geom, ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)) AND {$countable}", [json_encode($poly)])->n;
            if ($n >= $target) {
                return $poly;
            }
            if ($half > 6000) {
                return $n > 0 ? $poly : null;
            }
            $half *= $n > 0 ? max(1.1, min(2.0, sqrt($target / $n))) : 2.0;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function square(float $lng, float $lat, float $halfM): array
    {
        $dLat = $halfM / 111_320;
        $dLng = $halfM / (111_320 * cos(deg2rad($lat)));

        return Geo::polygon([[$lng - $dLng, $lat - $dLat], [$lng + $dLng, $lat - $dLat], [$lng + $dLng, $lat + $dLat], [$lng - $dLng, $lat + $dLat]]);
    }
}
