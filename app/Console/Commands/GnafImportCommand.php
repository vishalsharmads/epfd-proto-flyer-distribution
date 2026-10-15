<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gnaf\GnafDiscovery;
use App\Domain\Gnaf\GnafImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GnafImportCommand extends Command
{
    protected $signature = 'gnaf:import
        {--states= : Comma-separated states (default NSW,VIC; "ALL" for every state in the release)}
        {--sample : Use the bundled real-data extract in database/gnaf-sample (tests/CI)}
        {--path= : Override GNAF_PATH}
        {--fresh : Ignore resume state and re-stage every file}
        {--discover-only : Print what was found in the release and stop}';

    protected $description = 'Import Geoscape G-NAF (GDA2020) into addresses + derived suburbs using Postgres COPY';

    public function handle(GnafImporter $importer): int
    {
        $sample = (bool) $this->option('sample');
        $path = $sample ? (string) config('epfd.gnaf.sample_path') : (string) ($this->option('path') ?: config('epfd.gnaf.path'));
        if ($path === '') {
            $this->error('GNAF_PATH is not set. Set it in .env or pass --path, or use --sample.');

            return self::FAILURE;
        }

        $this->info("Discovering G-NAF release in: {$path}");
        $d = GnafDiscovery::scan($path);
        $this->printDiscovery($d);

        if ($this->option('discover-only')) {
            return self::SUCCESS;
        }

        $statesOpt = strtoupper((string) $this->option('states'));
        $states = match (true) {
            $statesOpt === 'ALL' => $d->states(),
            $statesOpt !== '' => array_values(array_filter(array_map('trim', explode(',', $statesOpt)))),
            $sample => $d->states(),
            default => (array) config('epfd.gnaf.default_states'),
        };
        $label = $sample ? config('epfd.gnaf.release_label').' (sample)' : ($d->releaseName ? $this->labelFrom($d->releaseName) : (string) config('epfd.gnaf.release_label'));

        $this->newLine();
        $this->info('Importing '.implode(', ', $states)." — release \"{$label}\", CRS GDA2020 (EPSG:7844)");
        $started = microtime(true);
        $release = $importer->onLog(fn (string $m) => $this->line($m))->import($d, $states, $label, $sample, (bool) $this->option('fresh'));

        $this->newLine();
        $this->info(sprintf('Import #%d completed in %.1f s', $release->id, microtime(true) - $started));
        $rows = [];
        foreach ((array) $release->kind_counts as $state => $c) {
            $sk = $release->skipped_counts[$state] ?? [];
            $rows[] = [$state, number_format($c['total']), number_format($c['houses']), number_format($c['units']),
                number_format($c['primary_with_units']), number_format($c['commercial']), number_format($c['letterboxes']),
                number_format($sk['aliases'] ?? 0), number_format($sk['retired'] ?? 0), number_format($sk['no_geocode'] ?? 0)];
        }
        $this->table(['State', 'Addresses', 'Houses', 'Units', 'Primary w/ units', 'Commercial', 'Letterboxes', 'Aliases skipped', 'Retired skipped', 'No geocode'], $rows);

        $this->printKnownSuburbs($states);

        return self::SUCCESS;
    }

    private function labelFrom(string $release): string
    {
        // "AUGUST 2026" → "AUG 2026"
        [$m, $y] = array_pad(explode(' ', $release), 2, '');

        return strtoupper(substr($m, 0, 3)).' '.$y;
    }

    private function printDiscovery(GnafDiscovery $d): void
    {
        $s = $d->summary();
        $this->line('  Release folder : '.($s['release'] ?? 'n/a'));
        $this->line('  Standard PSVs  : '.($s['standard_dir'] ?? 'NOT FOUND'));
        $this->line('  Authority codes: '.count($s['authority_codes']).' files ('.implode(', ', array_slice($s['authority_codes'], 0, 6)).'…)');
        $this->line('  Table SQL      : '.($s['create_tables_sql'] ?? 'not present'));
        $this->line('  Documents      : '.($s['documents'] ? implode(', ', array_slice($s['documents'], 0, 4)) : 'none'));
        $this->line('  ADDRESS_DETAIL columns ('.count($s['address_detail_columns']).'): '.implode(', ', $s['address_detail_columns']));
        foreach ($s['sql_vs_psv_mismatches'] as $table => $mm) {
            $this->warn("  {$table}: PSV has [".implode(',', $mm['only_in_psv']).'] not in release SQL; SQL has ['.implode(',', $mm['only_in_sql']).'] not in PSV — using PSV headers.');
        }
        $rows = [];
        foreach ($s['states'] as $st => $info) {
            $rows[] = [$st, $info['tables'], $info['address_detail_mb'].' MB', $info['has_primary_secondary'] ? 'yes' : 'no', $info['has_address_site'] ? 'yes' : 'no'];
        }
        $this->table(['State', 'PSV tables', 'ADDRESS_DETAIL size', 'PRIMARY_SECONDARY', 'ADDRESS_SITE'], $rows);
    }

    /** @param list<string> $states */
    private function printKnownSuburbs(array $states): void
    {
        $known = ['RYDALMERE', 'PARRAMATTA', 'ERMINGTON', 'WOLLONGONG', 'NEWCASTLE', 'RICHMOND', 'ABBOTSFORD', 'FITZROY', 'BONDI', 'NEW FARM'];
        $rows = DB::select('
            SELECT su.name, su.state, su.boundary_source, st.houses, st.units, st.primary_with_units, st.letterboxes
            FROM suburbs su JOIN suburb_stats st ON st.suburb_id = su.id
            WHERE upper(su.name) = ANY(?) AND su.state = ANY(?)
            ORDER BY su.state, su.name', ['{'.implode(',', array_map(fn ($k) => '"'.$k.'"', $known)).'}', '{'.implode(',', $states).'}']);
        if ($rows === []) {
            return;
        }
        $this->info('House/unit split for known suburbs (estimated letterboxes, anti double-count applied):');
        $this->table(['Suburb', 'State', 'Boundary', 'Houses', 'Units', 'Primaries excluded', 'Letterboxes'],
            array_map(fn ($r) => [$r->name, $r->state, $r->boundary_source, number_format($r->houses), number_format($r->units), number_format($r->primary_with_units), number_format($r->letterboxes)], $rows));
    }
}
