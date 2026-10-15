<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Runs\RunService;
use App\Exceptions\DomainProblem;
use App\Models\Run;
use Illuminate\Console\Command;

/**
 * Simulate a distributor's GPS track for runs in delivery.
 *   runs:simulate-gps            → every in_delivery run, full track at once
 *   runs:simulate-gps 12 --live  → append one fix every --interval seconds until the walk is done
 */
class RunsSimulateGpsCommand extends Command
{
    protected $signature = 'runs:simulate-gps {run? : Run id or reference} {--live : Append points over time} {--interval=3 : Seconds between points in --live mode} {--complete : Mark the run completed when the live walk finishes}';

    protected $description = 'Generate a realistic GPS walk along G-NAF address points for runs in delivery';

    public function handle(RunService $runs): int
    {
        $arg = $this->argument('run');
        $query = Run::query();
        if ($arg) {
            $query->where(fn ($q) => $q->where('reference', $arg)->orWhere('id', ctype_digit((string) $arg) ? (int) $arg : 0));
        } else {
            $query->where('status', 'in_delivery');
        }
        $list = $query->get();
        if ($list->isEmpty()) {
            $this->warn('No matching runs (status in_delivery).');

            return self::SUCCESS;
        }

        foreach ($list as $run) {
            try {
                if ($this->option('live')) {
                    $this->live($runs, $run);
                } else {
                    $r = $runs->simulateGps($run);
                    $this->info("{$run->reference}: {$r['points']} points, coverage ".($r['coverage_pct'] ?? '—').'%');
                }
            } catch (DomainProblem $e) {
                $this->error("{$run->reference}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function live(RunService $runs, Run $run): void
    {
        if ($run->status !== 'in_delivery') {
            $this->error("{$run->reference} is {$run->status}; move it to in_delivery first.");

            return;
        }
        $track = $runs->plannedTrack($run);
        $done = $run->gpsPoints()->count();
        $interval = max(1, (int) $this->option('interval'));
        $this->info("{$run->reference}: live walk, ".count($track)." points ({$done} already sent). Ctrl+C to stop.");
        $bar = $this->output->createProgressBar(count($track));
        $bar->advance($done);
        for ($i = $done, $n = count($track); $i < $n; $i++) {
            $runs->appendPoint($run, $track[$i][0], $track[$i][1]);
            if ($i % 20 === 0) {
                $runs->computeCoverage($run);
            }
            $bar->advance();
            sleep($interval);
        }
        $bar->finish();
        $this->newLine();
        $pct = $runs->computeCoverage($run);
        $this->info("Done. Coverage {$pct}%");
        if ($this->option('complete')) {
            $runs->transition($run->fresh(), 'completed');
        }
    }
}
