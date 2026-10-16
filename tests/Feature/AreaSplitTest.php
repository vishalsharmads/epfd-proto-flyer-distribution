<?php

declare(strict_types=1);

use App\Domain\Areas\AreaSplitter;
use App\Domain\Areas\LetterboxEstimator;
use App\Support\Geo;

/** Richmond VIC: the densest part of the local G-NAF sample (~1 km × 0.8 km). */
function richmondPolygon(): array
{
    return Geo::polygon([[144.992, -37.822], [145.004, -37.822], [145.004, -37.815], [144.992, -37.815]]);
}

it('splits an over-limit area into pieces that are each within the walking limit and keep every letterbox', function () {
    $whole = app(LetterboxEstimator::class)->estimate(richmondPolygon(), true, true)['estimate'];
    expect($whole->total())->toBeGreaterThan(500);

    config(['epfd.areas.max_walk_km' => max(1.0, round($whole->distanceKm / 3, 1)), 'epfd.areas.split_min_letterboxes' => 20]);

    $pieces = app(AreaSplitter::class)->split(richmondPolygon(), true, true, 'Richmond');

    expect(count($pieces))->toBeGreaterThan(1);
    foreach ($pieces as $piece) {
        expect($piece['geometry']['type'])->toBe('Polygon')
            ->and($piece['estimate']->distanceKm)->toBeLessThanOrEqual(config('epfd.areas.max_walk_km'))
            ->and($piece['estimate']->total())->toBeGreaterThanOrEqual(20);
    }
    // Addresses exactly on a shared border can count in both neighbours.
    expect(array_sum(array_map(fn ($p) => $p['estimate']->total(), $pieces)))->toBeGreaterThanOrEqual($whole->total())
        ->toBeLessThanOrEqual($whole->total() + 10);
});

it('returns the area unchanged when it is already within the limit', function () {
    config(['epfd.areas.max_walk_km' => 500]);

    $pieces = app(AreaSplitter::class)->split(richmondPolygon(), true, true, 'Richmond');

    expect($pieces)->toHaveCount(1);
});

it('splits through the API with a quote per piece', function () {
    config(['epfd.areas.max_walk_km' => 1]);

    $this->actingAs(businessUser())
        ->postJson(route('api.areas.split'), ['geometry' => richmondPolygon(), 'name' => 'Richmond'])
        ->assertOk()
        ->assertJsonPath('max_walk_km', 1)
        ->assertJsonStructure(['pieces' => [['name', 'geometry' => ['type', 'coordinates'], 'estimate' => ['houses', 'units', 'distance_km'], 'quote']]]);
});
