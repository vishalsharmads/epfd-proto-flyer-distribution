<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gnaf\GnafImporter;
use App\Domain\Gnaf\SuburbBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off upgrade for databases imported before suburbs were keyed by G-NAF LOCALITY_PID: backfill
 * addresses.locality_pid from the G-NAF staging tables, split same-named localities into their own suburbs
 * and recount. Existing suburb ids and serviced flags are kept. Re-run `suburbs:import` afterwards.
 */
class SuburbsRekeyCommand extends Command
{
    protected $signature = 'suburbs:rekey {--states= : Comma-separated states (default: every state with addresses)}';

    protected $description = 'Key suburbs by G-NAF locality_pid (splits same-named localities) and rebuild derived boundaries + stats';

    public function handle(GnafImporter $importer, SuburbBuilder $suburbs): int
    {
        $states = $this->option('states')
            ? array_map(fn (string $s) => strtoupper(trim($s)), explode(',', (string) $this->option('states')))
            : DB::table('addresses')->distinct()->orderBy('state')->pluck('state')->all();

        foreach ($states as $state) {
            $updated = $importer->backfillLocalityPids($state);
            if ($updated === null && DB::table('addresses')->where('state', $state)->whereNull('locality_pid')->exists()) {
                $this->error("{$state}: G-NAF staging tables are gone — run `php artisan gnaf:import --fresh --states={$state}` instead.");

                return self::FAILURE;
            }
            $this->info("{$state}: locality_pid set on ".number_format((int) $updated).' addresses');
        }

        $before = DB::table('suburbs')->whereIn('state', $states)->count();
        DB::statement('ANALYZE addresses');
        $suburbs->rebuildDerived($states);
        $suburbs->refreshStats($states);
        $after = DB::table('suburbs')->whereIn('state', $states)->count();
        $unkeyed = DB::table('suburbs')->whereIn('state', $states)->whereNull('locality_pid')->count();

        $this->info("Suburbs: {$before} → {$after} (".($after - $before).' same-named localities split out).');
        if ($unkeyed > 0) {
            $this->warn("{$unkeyed} suburb(s) have no matching G-NAF locality (kept, but no longer counted).");
        }
        $this->line('Next: php artisan suburbs:import <Geoscape localities .shp> --source=Geoscape');

        return self::SUCCESS;
    }
}
