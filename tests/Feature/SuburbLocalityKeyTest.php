<?php

declare(strict_types=1);

use App\Domain\Gnaf\SuburbBuilder;
use App\Models\Suburb;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Seed $count house addresses for one G-NAF locality on a ~100 m grid in the test-only state "ZZZ". */
function seedLocality(string $pid, string $name, float $lng, float $lat, int $count): void
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $x = $lng + ($i % 5) * 0.001;
        $y = $lat + intdiv($i, 5) * 0.001;
        $rows[] = [
            'gnaf_pid' => "GAZZZ{$pid}{$i}", 'state' => 'ZZZ', 'locality' => strtoupper($name), 'locality_pid' => $pid, 'postcode' => '9999',
            'address_kind' => 'house', 'full_address' => "{$i} TEST ST, {$name}", 'search_text' => "{$i} test st",
            'geom' => DB::raw("ST_SetSRID(ST_MakePoint({$x}, {$y}), 7844)"),
        ];
    }
    DB::table('addresses')->insert($rows);
}

/** @param list<array{properties: array<string, string>, ring: list<array{0: float, 1: float}>}> $features */
function boundaryFile(array $features): string
{
    $path = storage_path('app/private/test-boundaries-'.uniqid().'.geojson');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => array_map(fn ($f) => [
        'type' => 'Feature', 'properties' => $f['properties'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$f['ring']]],
    ], $features)]));

    return $path;
}

/** @return list<array{0: float, 1: float}> */
function squareRing(float $lng, float $lat): array
{
    return [[$lng - 0.01, $lat - 0.01], [$lng + 0.02, $lat - 0.01], [$lng + 0.02, $lat + 0.02], [$lng - 0.01, $lat + 0.02], [$lng - 0.01, $lat - 0.01]];
}

beforeEach(function () {
    seedLocality('locZZZmay0001', 'Mayfield', 151.70, -32.90, 30);
    seedLocality('locZZZmay0002', 'Mayfield', 149.60, -34.70, 12);
});

it('keys sample suburbs by their G-NAF locality pid', function () {
    expect(DB::table('addresses')->where('state', '<>', 'ZZZ')->whereNull('locality_pid')->exists())->toBeFalse()
        ->and(DB::table('suburbs')->whereNotNull('locality_pid')->count())->toBe(DB::table('suburbs')->count());
});

it('keeps same-named localities in one state as separate suburbs with their own counts', function () {
    app(SuburbBuilder::class)->rebuildDerived(['ZZZ']);
    app(SuburbBuilder::class)->refreshStats(['ZZZ']);

    $suburbs = Suburb::with('stats')->where('state', 'ZZZ')->orderBy('locality_pid')->get();

    expect($suburbs)->toHaveCount(2)
        ->and($suburbs->pluck('name')->unique()->all())->toBe(['Mayfield'])
        ->and($suburbs->pluck('locality_pid')->all())->toBe(['locZZZmay0001', 'locZZZmay0002'])
        ->and($suburbs[0]->stats->letterboxes)->toBe(30)
        ->and($suburbs[1]->stats->letterboxes)->toBe(12);
});

it('gives a pre-existing suburb the largest same-named locality, keeping its id and serviced flag', function () {
    $legacy = Suburb::factory()->create(['name' => 'Mayfield', 'state' => 'ZZZ', 'boundary_source' => 'official', 'is_serviced' => false]);

    app(SuburbBuilder::class)->rebuildDerived(['ZZZ']);

    $legacy->refresh();
    expect($legacy->locality_pid)->toBe('locZZZmay0001')
        ->and($legacy->is_serviced)->toBeFalse()
        ->and($legacy->boundary_source)->toBe('derived')
        ->and(Suburb::where('locality_pid', 'locZZZmay0002')->exists())->toBeTrue();
});

it('matches official boundaries on locality pid, so same-named localities each get their own', function () {
    app(SuburbBuilder::class)->rebuildDerived(['ZZZ']);
    $path = boundaryFile([
        ['properties' => ['LOC_PID' => 'locZZZmay0001', 'LOC_NAME' => 'Mayfield', 'STATE' => 'ZZZ'], 'ring' => squareRing(151.70, -32.90)],
        ['properties' => ['LOC_PID' => 'locZZZmay0002', 'LOC_NAME' => 'Mayfield', 'STATE' => 'ZZZ'], 'ring' => squareRing(149.60, -34.70)],
        ['properties' => ['LOC_PID' => 'locZZZnone999', 'LOC_NAME' => 'Nowhere', 'STATE' => 'ZZZ'], 'ring' => squareRing(140.0, -30.0)],
    ]);

    $this->artisan('suburbs:import', ['path' => [$path], '--source' => 'Geoscape'])->assertSuccessful();

    $rows = DB::select("SELECT locality_pid, boundary_source, boundary_matched_by, ST_X(centroid) AS lng FROM suburbs WHERE state = 'ZZZ' ORDER BY locality_pid");
    expect(collect($rows)->pluck('boundary_source')->all())->toBe(['official', 'official'])
        ->and(collect($rows)->pluck('boundary_matched_by')->all())->toBe(['locality_pid', 'locality_pid'])
        ->and($rows[0]->lng)->toBeGreaterThan(151.6)
        ->and($rows[1]->lng)->toBeLessThan(149.7);

    $run = DB::table('suburb_import_runs')->latest('id')->first();
    expect($run->matched)->toBe(2)->and($run->matched_by)->toBe('locality_pid')
        ->and(json_decode($run->unmatched, true))->toBe(['Nowhere']);
});

it('skips names shared by several suburbs when matching by name', function () {
    app(SuburbBuilder::class)->rebuildDerived(['ZZZ']);
    $path = boundaryFile([['properties' => ['SAL_NAME26' => 'Mayfield (ZZZ)', 'STE_NAME26' => 'ZZZ'], 'ring' => squareRing(151.70, -32.90)]]);

    $this->artisan('suburbs:import', ['path' => [$path]])->assertSuccessful();

    expect(DB::table('suburbs')->where('state', 'ZZZ')->where('boundary_source', 'official')->exists())->toBeFalse();
});

it('never lets a name match overwrite a boundary matched by locality pid', function () {
    seedLocality('locZZZuniq001', 'Uniqueville', 150.10, -33.50, 10);
    app(SuburbBuilder::class)->rebuildDerived(['ZZZ']);
    $pidFile = boundaryFile([['properties' => ['LOC_PID' => 'locZZZuniq001', 'LOC_NAME' => 'Uniqueville', 'STATE' => 'ZZZ'], 'ring' => squareRing(150.10, -33.50)]]);
    $nameFile = boundaryFile([['properties' => ['SAL_NAME26' => 'Uniqueville', 'STE_NAME26' => 'ZZZ'], 'ring' => squareRing(155.0, -20.0)]]);

    $this->artisan('suburbs:import', ['path' => [$pidFile, $nameFile]])->assertSuccessful();

    $lng = (float) DB::selectOne("SELECT ST_X(centroid) AS lng FROM suburbs WHERE locality_pid = 'locZZZuniq001'")->lng;
    expect($lng)->toBeLessThan(151.0);
});

it('streams Shapefiles through ogr2ogr and matches them on locality pid', function () {
    app(SuburbBuilder::class)->rebuildDerived(['ZZZ']);
    $geojson = boundaryFile([['properties' => ['LOC_PID' => 'locZZZmay0002', 'LOC_NAME' => 'Mayfield', 'STATE' => 'ZZZ'], 'ring' => squareRing(149.60, -34.70)]]);
    $shp = dirname($geojson).'/test-localities-'.uniqid().'.shp';
    (new Process(['ogr2ogr', '-f', 'ESRI Shapefile', '-a_srs', 'EPSG:7844', $shp, $geojson]))->mustRun();

    $this->artisan('suburbs:import', ['path' => [$shp], '--source' => 'Geoscape'])->assertSuccessful();

    expect(DB::table('suburbs')->where('locality_pid', 'locZZZmay0002')->value('boundary_matched_by'))->toBe('locality_pid')
        ->and(DB::table('suburbs')->where('locality_pid', 'locZZZmay0001')->value('boundary_source'))->toBe('derived');
});
