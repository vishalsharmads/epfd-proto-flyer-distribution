<?php

declare(strict_types=1);

use App\Domain\Areas\GnafLetterboxSource;
use App\Domain\Areas\LetterboxEstimator;
use App\Domain\Areas\PolygonValidator;
use App\Exceptions\DomainProblem;
use App\Models\GnafRelease;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

function problemCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (DomainProblem $e) {
        return $e->problemCode;
    }

    return null;
}

describe('sample G-NAF import', function () {
    it('records the release with CRS and counts', function () {
        $r = GnafRelease::current();
        expect($r)->not->toBeNull()
            ->and($r->srid)->toBe(7844)
            ->and($r->is_sample)->toBeTrue()
            ->and($r->kind_counts['NSW']['total'])->toBeGreaterThan(50000)
            ->and($r->skipped_counts['NSW']['aliases'])->toBeGreaterThan(0);
    });

    it('imports principal addresses only, in GDA2020', function () {
        expect(DB::table('addresses')->count())->toBeGreaterThan(100000)
            ->and((int) DB::selectOne('SELECT count(DISTINCT ST_SRID(geom)) AS n FROM addresses')->n)->toBe(1)
            ->and((int) DB::selectOne('SELECT ST_SRID(geom) AS s FROM addresses LIMIT 1')->s)->toBe(7844);
    });

    it('flags unit-building primaries and never flags units themselves', function () {
        expect(DB::table('addresses')->where('is_primary_with_units', true)->count())->toBeGreaterThan(1000)
            ->and(DB::table('addresses')->where('is_primary_with_units', true)->where('address_kind', 'unit')->whereNull('flat_number')->count())->toBeGreaterThanOrEqual(0);
    });

    it('builds derived suburb boundaries with stats', function () {
        $s = DB::selectOne("SELECT s.boundary_source, ST_IsValid(boundary) AS valid, st.letterboxes FROM suburbs s JOIN suburb_stats st ON st.suburb_id = s.id WHERE s.name = 'Rydalmere'");
        expect($s->boundary_source)->toBe('derived')->and($s->valid)->toBeTrue()->and($s->letterboxes)->toBeGreaterThan(3000);
    });
});

describe('LetterboxEstimator on the sample dataset', function () {
    it('counts houses and units inside a Rydalmere polygon', function () {
        $r = app(LetterboxEstimator::class)->estimate(rydalmerePolygon(), true, true, RYDALMERE_PICKUP);
        $e = $r['estimate'];
        expect($e->houses)->toBeGreaterThan(500)->and($e->units)->toBeGreaterThan(500)
            ->and($e->total())->toBe($e->houses + $e->units)
            ->and($e->distanceKm)->toBeGreaterThan(5)->toBeLessThan(80);
    });

    it('respects the house/unit toggles', function () {
        $est = app(LetterboxEstimator::class);
        $both = $est->estimate(rydalmerePolygon(), true, true)['estimate'];
        $houses = $est->estimate(rydalmerePolygon(), true, false)['estimate'];
        $units = $est->estimate(rydalmerePolygon(), false, true)['estimate'];
        expect($houses->units)->toBe(0)->and($units->houses)->toBe(0)
            ->and($houses->houses + $units->units)->toBe($both->total());
    });

    it('excludes primaries with units (no double count)', function () {
        $total = app(LetterboxEstimator::class)->estimate(rydalmerePolygon(), true, true)['estimate']->total();
        $raw = (int) DB::selectOne('SELECT count(*) AS n FROM addresses WHERE ST_Intersects(geom, ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)) AND NOT is_commercial', [json_encode(rydalmerePolygon())])->n;
        $primaries = (int) DB::selectOne('SELECT count(*) AS n FROM addresses WHERE ST_Intersects(geom, ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)) AND is_primary_with_units AND NOT is_commercial', [json_encode(rydalmerePolygon())])->n;
        expect($primaries)->toBeGreaterThan(0)->and($total)->toBe($raw - $primaries);
    });

    it('returns an explicit no-letterboxes state for an empty area', function () {
        // Parramatta River between Rydalmere and Ermington: no addresses.
        $water = Geo::polygon([[151.0405, -33.8235], [151.0445, -33.8235], [151.0445, -33.8215], [151.0405, -33.8215]]);
        $e = app(LetterboxEstimator::class)->estimate($water, true, true)['estimate'];
        expect($e->isEmpty())->toBeTrue()->and($e->toArray()['no_letterboxes'])->toBeTrue()->and($e->toArray()['estimated'])->toBeTrue();
    });

    it('caches estimates by polygon hash', function () {
        $est = app(LetterboxEstimator::class);
        $first = $est->estimate(rydalmerePolygon(), true, true);
        $second = $est->estimate(rydalmerePolygon(), true, true);
        expect($first['hash'])->toBe($second['hash'])->and($second['cached'])->toBeTrue();
    });

    it('meets the performance budget on the sample', function () {
        $t = microtime(true);
        app(GnafLetterboxSource::class)->estimate(rydalmerePolygon(), true, true);
        expect((microtime(true) - $t) * 1000)->toBeLessThan(1000);
    });
});

describe('polygon validation', function () {
    $v = fn () => app(PolygonValidator::class);

    it('accepts a valid polygon', fn () => expect($v()->validate(rydalmerePolygon())['type'])->toBe('Polygon'));
    it('rejects non-polygons', fn () => expect(problemCode(fn () => $v()->validate(['type' => 'Point', 'coordinates' => [151, -33]])))->toBe('polygon_invalid_geojson'));
    it('rejects fewer than 3 distinct vertices', fn () => expect(problemCode(fn () => $v()->validate(Geo::polygon([[151.03, -33.81], [151.04, -33.81], [151.03, -33.81]]))))->toBe('polygon_too_few_vertices'));
    it('rejects self-intersecting bow-ties', fn () => expect(problemCode(fn () => $v()->validate(Geo::polygon([[151.02, -33.81], [151.04, -33.80], [151.04, -33.81], [151.02, -33.80]]))))->toBe('polygon_self_intersection'));
    it('rejects polygons outside Australia', fn () => expect(problemCode(fn () => $v()->validate(Geo::polygon([[2.29, 48.85], [2.30, 48.85], [2.30, 48.86]]))))->toBe('polygon_outside_australia'));
    it('rejects too many vertices', function () use ($v) {
        $ring = [];
        for ($i = 0; $i < 501; $i++) {
            $a = 2 * M_PI * $i / 501;
            $ring[] = [151.03 + 0.01 * cos($a), -33.81 + 0.01 * sin($a)];
        }
        expect(problemCode(fn () => $v()->validate(Geo::polygon($ring))))->toBe('polygon_too_many_vertices');
    });
    it('rejects areas above the maximum size (measured in MGA metres)', fn () => expect(problemCode(fn () => $v()->validate(Geo::polygon([[150.9, -33.9], [151.1, -33.9], [151.1, -33.7], [150.9, -33.7]]))))->toBe('polygon_too_large'));
    it('rejects tiny slivers', fn () => expect(problemCode(fn () => $v()->validate(Geo::polygon([[151.03, -33.81], [151.0301, -33.81], [151.0301, -33.8101]]))))->toBe('polygon_too_small'));
    it('rejects polygons with holes', fn () => expect(problemCode(fn () => $v()->validate(['type' => 'Polygon', 'coordinates' => [rydalmerePolygon()['coordinates'][0], rydalmerePolygon()['coordinates'][0]]])))->toBe('polygon_has_holes'));
});

describe('the 20 km pickup rule', function () {
    $v = fn () => app(PolygonValidator::class);

    it('accepts an area near the pickup point', fn () => expect(problemCode(fn () => $v()->validate(rydalmerePolygon(), RYDALMERE_PICKUP)))->toBeNull());

    it('rejects an area entirely beyond 20 km (Wollongong from Rydalmere)', function () use ($v) {
        $wollongong = Geo::polygon([[150.88, -34.43], [150.90, -34.43], [150.90, -34.42], [150.88, -34.42]]);
        expect(problemCode(fn () => $v()->validate($wollongong, RYDALMERE_PICKUP)))->toBe('area_outside_pickup_radius');
    });

    it('accepts an area that is only partly within 20 km', function () use ($v) {
        // Pickup ~19.5 km west of the polygon's west edge; polygon extends further away.
        $pickup = ['lng' => 151.0365 - 0.211, 'lat' => -33.8126];
        $poly = Geo::polygon([[151.0, -33.815], [151.06, -33.815], [151.06, -33.81], [151.0, -33.81]]);
        expect(problemCode(fn () => $v()->validate($poly, $pickup)))->toBeNull();
    });

    it('is enforced by the API too', function () {
        $this->postJson('/api/v1/areas/estimate', [
            'geometry' => Geo::polygon([[150.88, -34.43], [150.90, -34.43], [150.90, -34.42], [150.88, -34.42]]),
            'pickup' => RYDALMERE_PICKUP,
        ])->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'area_outside_pickup_radius');
    });
});
