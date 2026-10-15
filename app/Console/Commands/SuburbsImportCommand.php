<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Replace derived (approximate) suburb boundaries with official ones — ABS SAL 2021 or Geoscape localities.
 * Input: GeoJSON FeatureCollection in GDA2020 (or a Shapefile/GeoPackage, converted with ogr2ogr).
 * Matching: normalised name (case-insensitive, " (NSW)" style suffix stripped) + state.
 */
class SuburbsImportCommand extends Command
{
    protected $signature = 'suburbs:import {path : GeoJSON, Shapefile or GeoPackage} {--source=ABS : Source label stored in the import history}';

    protected $description = 'Import official suburb (SAL/locality) boundaries and replace derived ones';

    private const NAME_FIELDS = ['SAL_NAME21', 'SAL_NAME_2021', 'SAL_NAME', 'LOCALITY_NAME', 'LOC_NAME', 'locality_name', 'NAME', 'name', 'suburb', 'SUBURB'];

    private const STATE_FIELDS = ['STE_NAME21', 'STE_NAME_2021', 'STATE_NAME', 'STE_CODE21', 'STATE', 'state', 'STATE_ABBREVIATION', 'state_abbreviation'];

    private const STATES = [
        'NEW SOUTH WALES' => 'NSW', 'VICTORIA' => 'VIC', 'QUEENSLAND' => 'QLD', 'SOUTH AUSTRALIA' => 'SA', 'WESTERN AUSTRALIA' => 'WA',
        'TASMANIA' => 'TAS', 'NORTHERN TERRITORY' => 'NT', 'AUSTRALIAN CAPITAL TERRITORY' => 'ACT', 'OTHER TERRITORIES' => 'OT',
        '1' => 'NSW', '2' => 'VIC', '3' => 'QLD', '4' => 'SA', '5' => 'WA', '6' => 'TAS', '7' => 'NT', '8' => 'ACT', '9' => 'OT',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }
        $geojsonPath = $this->toGeoJson($path);
        if ($geojsonPath === null) {
            return self::FAILURE;
        }
        $fc = json_decode((string) file_get_contents($geojsonPath), true);
        if (! is_array($fc) || ($fc['type'] ?? null) !== 'FeatureCollection') {
            $this->error('Expected a GeoJSON FeatureCollection.');

            return self::FAILURE;
        }

        $features = $fc['features'] ?? [];
        $first = $features[0]['properties'] ?? [];
        $nameField = collect(self::NAME_FIELDS)->first(fn ($f) => array_key_exists($f, $first));
        $stateField = collect(self::STATE_FIELDS)->first(fn ($f) => array_key_exists($f, $first));
        if (! $nameField) {
            $this->error('Could not find a name property (tried '.implode(', ', self::NAME_FIELDS).').');

            return self::FAILURE;
        }
        $this->info("Using name field '{$nameField}'".($stateField ? ", state field '{$stateField}'" : ' (no state field: state from name suffix)'));

        $matched = 0;
        $unmatched = [];
        $bar = $this->output->createProgressBar(count($features));
        foreach ($features as $f) {
            $bar->advance();
            $raw = trim((string) ($f['properties'][$nameField] ?? ''));
            if ($raw === '' || empty($f['geometry'])) {
                continue;
            }
            [$name, $suffixState] = $this->normaliseName($raw);
            $state = $this->normaliseState($stateField ? (string) ($f['properties'][$stateField] ?? '') : '') ?? $suffixState;

            $id = DB::table('suburbs')->whereRaw('upper(name) = ?', [$name])
                ->when($state, fn ($q) => $q->where('state', $state))->value('id');
            if (! $id) {
                $unmatched[] = trim($raw.($state && ! $suffixState ? " {$state}" : ''));

                continue;
            }
            DB::update("
                UPDATE suburbs SET
                    boundary = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844))), 3)),
                    boundary_source = 'official',
                    centroid = ST_PointOnSurface(ST_MakeValid(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)))),
                    updated_at = now()
                WHERE id = ?", [json_encode($f['geometry']), json_encode($f['geometry']), $id]);
            $matched++;
        }
        $bar->finish();
        $this->newLine(2);

        DB::table('suburb_import_runs')->insert([
            'source' => mb_substr((string) $this->option('source'), 0, 40),
            'path' => mb_substr($path, -255),
            'features' => count($features),
            'matched' => $matched,
            'unmatched' => json_encode(array_values($unmatched)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->info("Matched {$matched} of ".count($features).' features; boundary_source set to official.');
        if ($unmatched) {
            $this->warn(count($unmatched).' unmatched name(s):');
            foreach (array_slice($unmatched, 0, 50) as $u) {
                $this->line("  - {$u}");
            }
            if (count($unmatched) > 50) {
                $this->line('  … '.(count($unmatched) - 50).' more (see Admin → G-NAF data status)');
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

    private function toGeoJson(string $path): ?string
    {
        if (preg_match('/\.(geo)?json$/i', $path)) {
            return $path;
        }
        $out = storage_path('app/private/suburbs-import-'.md5($path).'.geojson');
        @mkdir(dirname($out), 0775, true);
        @unlink($out);
        $this->info('Converting with ogr2ogr (→ EPSG:7844)…');
        $p = new Process(['ogr2ogr', '-f', 'GeoJSON', '-t_srs', 'EPSG:7844', $out, $path]);
        $p->setTimeout(1800)->run();
        if (! $p->isSuccessful()) {
            $this->error('ogr2ogr failed: '.$p->getErrorOutput());

            return null;
        }

        return $out;
    }
}
