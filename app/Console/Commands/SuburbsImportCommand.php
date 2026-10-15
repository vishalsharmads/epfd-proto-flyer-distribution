<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Replace derived (approximate) suburb boundaries with official ones.
 *
 * Preferred: Geoscape Administrative Boundaries → Localities (data.gov.au, CC BY 4.0, quarterly). Its LOC_PID is
 * the G-NAF LOCALITY_PID, so every gazetted locality matches exactly — including same-named ones in one state.
 * Also accepted: ABS Suburbs and Localities (SAL), a mesh-block approximation of localities with no pid. These
 * match on normalised name (case-insensitive, " (NSW)" style suffix stripped) + state; names shared by several
 * suburbs are skipped as ambiguous, and name matches never overwrite a boundary that was matched by pid.
 *
 * Input: GeoJSON FeatureCollection in GDA2020, or Shapefile/GeoPackage (converted with ogr2ogr). Geoscape ships
 * one file per state, so several paths can be given at once.
 */
class SuburbsImportCommand extends Command
{
    protected $signature = 'suburbs:import {path* : GeoJSON, Shapefile or GeoPackage file(s)} {--source=ABS : Source label stored in the import history}';

    protected $description = 'Import official suburb (Geoscape locality / ABS SAL) boundaries and replace derived ones';

    /** GeoJSON files up to this size are decoded in one go; larger ones are streamed via ogr2ogr. */
    private const DIRECT_JSON_MAX_BYTES = 50_000_000;

    private const PID_FIELDS = ['LOC_PID', 'LOCALITY_PID', 'loc_pid', 'locality_pid'];

    private const NAME_FIELDS = ['LOC_NAME', 'SAL_NAME26', 'SAL_NAME21', 'SAL_NAME_2021', 'SAL_NAME', 'LOCALITY_NAME', 'locality_name', 'NAME', 'name', 'suburb', 'SUBURB'];

    private const STATE_FIELDS = ['STATE', 'STE_NAME26', 'STE_NAME21', 'STE_NAME_2021', 'STATE_NAME', 'STE_CODE21', 'state', 'STATE_ABBREVIATION', 'state_abbreviation'];

    private const STATES = [
        'NEW SOUTH WALES' => 'NSW', 'VICTORIA' => 'VIC', 'QUEENSLAND' => 'QLD', 'SOUTH AUSTRALIA' => 'SA', 'WESTERN AUSTRALIA' => 'WA',
        'TASMANIA' => 'TAS', 'NORTHERN TERRITORY' => 'NT', 'AUSTRALIAN CAPITAL TERRITORY' => 'ACT', 'OTHER TERRITORIES' => 'OT',
        '1' => 'NSW', '2' => 'VIC', '3' => 'QLD', '4' => 'SA', '5' => 'WA', '6' => 'TAS', '7' => 'NT', '8' => 'ACT', '9' => 'OT',
    ];

    public function handle(): int
    {
        $status = self::SUCCESS;
        foreach ((array) $this->argument('path') as $path) {
            if ($this->importFile((string) $path) !== self::SUCCESS) {
                $status = self::FAILURE;
            }
        }

        $left = DB::table('suburbs')->where('boundary_source', 'derived')->whereNotNull('locality_pid')->count();
        $this->info("Suburbs still on a derived boundary: {$left}");

        return $status;
    }

    private function importFile(string $path): int
    {
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }
        $this->info("— {$path}");
        $source = $this->toFeatureSource($path);
        if ($source === null) {
            return self::FAILURE;
        }
        $total = $this->countFeatures($source);
        $first = [];
        foreach ($this->features($source) as $f) {
            $first = $f['properties'] ?? [];
            break;
        }
        $pidField = collect(self::PID_FIELDS)->first(fn ($f) => array_key_exists($f, $first));
        $nameField = collect(self::NAME_FIELDS)->first(fn ($f) => array_key_exists($f, $first));
        $stateField = collect(self::STATE_FIELDS)->first(fn ($f) => array_key_exists($f, $first));
        if (! $pidField && ! $nameField) {
            $this->error('Could not find a locality pid ('.implode(', ', self::PID_FIELDS).') or name property ('.implode(', ', self::NAME_FIELDS).').');

            return self::FAILURE;
        }
        $this->info($pidField
            ? "Matching on G-NAF locality pid '{$pidField}'"
            : "Matching on name '{$nameField}'".($stateField ? " + state '{$stateField}'" : ' (no state field: state from name suffix)'));

        $matched = 0;
        $unmatched = [];
        $ambiguous = [];
        $bar = $this->output->createProgressBar($total);
        foreach ($this->features($source) as $f) {
            $bar->advance();
            if (empty($f['geometry'])) {
                continue;
            }
            $props = $f['properties'] ?? [];
            $raw = trim((string) ($props[$nameField] ?? ''));
            [$name, $suffixState] = $this->normaliseName($raw);
            $state = $this->normaliseState($stateField ? (string) ($props[$stateField] ?? '') : '') ?? $suffixState;
            $label = trim($raw.($state && ! $suffixState ? " {$state}" : ''));

            if ($pidField) {
                $pid = trim((string) ($props[$pidField] ?? ''));
                $id = $pid === '' ? null : DB::table('suburbs')->where('locality_pid', $pid)->value('id');
            } else {
                if ($name === '') {
                    continue;
                }
                $ids = DB::table('suburbs')->whereRaw('upper(name) = ?', [$name])
                    ->when($state, fn ($q) => $q->where('state', $state))->limit(2)->pluck('id');
                if ($ids->count() > 1) {
                    $ambiguous[] = $label;

                    continue;
                }
                $id = $ids->first();
            }
            if (! $id) {
                $unmatched[] = $label;

                continue;
            }

            $matched += DB::update("
                UPDATE suburbs SET
                    boundary = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844))), 3)),
                    boundary_source = 'official',
                    boundary_matched_by = ?,
                    centroid = ST_PointOnSurface(ST_MakeValid(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)))),
                    updated_at = now()
                WHERE id = ?".($pidField ? '' : " AND boundary_matched_by IS DISTINCT FROM 'locality_pid'"),
                [json_encode($f['geometry']), $pidField ? 'locality_pid' : 'name', json_encode($f['geometry']), $id]);
        }
        $bar->finish();
        $this->newLine(2);

        DB::table('suburb_import_runs')->insert([
            'source' => mb_substr((string) $this->option('source'), 0, 40),
            'path' => mb_substr($path, -255),
            'features' => $total,
            'matched' => $matched,
            'matched_by' => $pidField ? 'locality_pid' : 'name',
            'unmatched' => json_encode(array_values(array_merge($unmatched, array_map(fn ($a) => "{$a} (ambiguous name)", $ambiguous)))),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->info("Matched {$matched} of {$total} features; boundary_source set to official.");
        if ($ambiguous) {
            $this->warn(count($ambiguous).' name(s) shared by several suburbs were skipped (use a file with locality pids): '.implode(', ', array_slice($ambiguous, 0, 20)));
        }
        if ($unmatched) {
            $this->warn(count($unmatched).' feature(s) with no suburb'.($pidField ? ' (localities with no G-NAF addresses loaded)' : '').':');
            foreach (array_slice($unmatched, 0, 20) as $u) {
                $this->line("  - {$u}");
            }
            if (count($unmatched) > 20) {
                $this->line('  … '.(count($unmatched) - 20).' more (see Admin → G-NAF data status)');
            }
        }

        return self::SUCCESS;
    }

    /** @return array{0:string,1:?string} upper-cased name, state parsed from a " (NSW)" suffix */
    private function normaliseName(string $raw): array
    {
        $state = null;
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $raw, $m)) {
            $raw = $m[1];
            $inner = strtoupper(trim(preg_replace('/\.|\s+-.*$/', '', $m[2]) ?? ''));
            $state = $this->normaliseState($inner);
        }

        return [mb_strtoupper(preg_replace('/\s+/', ' ', trim($raw)) ?? ''), $state];
    }

    private function normaliseState(string $s): ?string
    {
        $s = strtoupper(trim($s));
        if ($s === '') {
            return null;
        }
        if (in_array($s, ['ACT', 'NSW', 'NT', 'OT', 'QLD', 'SA', 'TAS', 'VIC', 'WA'], true)) {
            return $s;
        }

        return self::STATES[$s] ?? null;
    }

    /**
     * Small GeoJSON files are read directly; anything else (Shapefile, GeoPackage, large GeoJSON) is converted with
     * ogr2ogr to GeoJSON Sequence — one feature per line — so a 250 MB state file streams instead of being decoded at once.
     *
     * @return array{path: string, sequence: bool}|null
     */
    private function toFeatureSource(string $path): ?array
    {
        if (preg_match('/\.(geo)?json$/i', $path) && filesize($path) < self::DIRECT_JSON_MAX_BYTES) {
            $fc = json_decode((string) file_get_contents($path), true);
            if (! is_array($fc) || ($fc['type'] ?? null) !== 'FeatureCollection') {
                $this->error('Expected a GeoJSON FeatureCollection.');

                return null;
            }

            return ['path' => $path, 'sequence' => false];
        }
        $out = storage_path('app/private/suburbs-import-'.md5($path).'.geojsonl');
        @mkdir(dirname($out), 0775, true);
        @unlink($out);
        $this->info('Converting with ogr2ogr (→ EPSG:7844, GeoJSON Sequence)…');
        $p = new Process(['ogr2ogr', '-f', 'GeoJSONSeq', '-t_srs', 'EPSG:7844', $out, $path]);
        $p->setTimeout(1800)->run();
        if (! $p->isSuccessful()) {
            $this->error('ogr2ogr failed: '.$p->getErrorOutput());

            return null;
        }

        return ['path' => $out, 'sequence' => true];
    }

    /**
     * @param  array{path: string, sequence: bool}  $source
     * @return \Generator<int, array<string, mixed>>
     */
    private function features(array $source): \Generator
    {
        if (! $source['sequence']) {
            yield from json_decode((string) file_get_contents($source['path']), true)['features'] ?? [];

            return;
        }
        $handle = fopen($source['path'], 'rb');
        try {
            while (($line = fgets($handle)) !== false) {
                $feature = json_decode(ltrim($line, "\x1e \t"), true);
                if (is_array($feature)) {
                    yield $feature;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array{path: string, sequence: bool} $source */
    private function countFeatures(array $source): int
    {
        if (! $source['sequence']) {
            return count(json_decode((string) file_get_contents($source['path']), true)['features'] ?? []);
        }
        $count = 0;
        $handle = fopen($source['path'], 'rb');
        while (($line = fgets($handle)) !== false) {
            $count += trim($line) === '' ? 0 : 1;
        }
        fclose($handle);

        return $count;
    }
}
