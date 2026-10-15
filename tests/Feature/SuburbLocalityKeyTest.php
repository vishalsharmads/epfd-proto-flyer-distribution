<?php

declare(strict_types=1);

use App\Domain\Gnaf\SuburbBuilder;
use App\Models\Suburb;
use Illuminate\Support\Facades\DB;

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
