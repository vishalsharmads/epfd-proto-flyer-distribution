<?php

declare(strict_types=1);

namespace App\Domain\Areas;

use App\Exceptions\DomainProblem;
use Illuminate\Support\Facades\DB;

/**
 * Server-side polygon validation (never trust the client):
 *  - GeoJSON Polygon with one outer ring, numeric lon/lat
 *  - ≥ 3 distinct vertices, ≤ max_vertices
 *  - every vertex inside the Australian bounding box
 *  - ST_IsValid (no self-intersection); trivial issues are repaired with ST_MakeValid only
 *    when the repaired shape stays a single polygon
 *  - area between min_area_m2 and max_area_km2, measured in the MGA2020 zone (metres)
 *  - optional: at least partly within pickup_radius_km of the pickup point
 */
final class PolygonValidator
{
    /**
     * @param  array<string, mixed>  $geometry
     * @return array{type:string, coordinates: list<list<array{0:float,1:float}>>} normalised polygon
     */
    public function validate(array $geometry, ?array $pickup = null): array
    {
        $cfg = (array) config('epfd.areas');

        if (($geometry['type'] ?? null) !== 'Polygon' || ! is_array($geometry['coordinates'] ?? null) || ! is_array($geometry['coordinates'][0] ?? null)) {
            throw new DomainProblem('polygon_invalid_geojson', 'The area must be a GeoJSON Polygon.');
        }
        if (count($geometry['coordinates']) > 1) {
            throw new DomainProblem('polygon_has_holes', 'Areas cannot contain holes.');
        }

        $ring = [];
        foreach ($geometry['coordinates'][0] as $pt) {
            if (! is_array($pt) || count($pt) < 2 || ! is_numeric($pt[0]) || ! is_numeric($pt[1])) {
                throw new DomainProblem('polygon_invalid_geojson', 'Every vertex must be a [longitude, latitude] pair.');
            }
            $ring[] = [round((float) $pt[0], 7), round((float) $pt[1], 7)];
        }
        if ($ring !== [] && $ring[0] === $ring[count($ring) - 1]) {
            array_pop($ring);
        }

        $distinct = array_unique(array_map(fn ($p) => $p[0].','.$p[1], $ring));
        if (count($distinct) < 3) {
            throw new DomainProblem('polygon_too_few_vertices', 'An area needs at least 3 distinct pins.');
        }
        if (count($ring) > (int) $cfg['max_vertices']) {
            throw new DomainProblem('polygon_too_many_vertices', "An area can have at most {$cfg['max_vertices']} pins.");
        }

        [$minX, $minY, $maxX, $maxY] = $cfg['australia_bbox'];
        foreach ($ring as [$x, $y]) {
            if ($x < $minX || $x > $maxX || $y < $minY || $y > $maxY) {
                throw new DomainProblem('polygon_outside_australia', 'Areas must be inside Australia.');
            }
        }

        $polygon = ['type' => 'Polygon', 'coordinates' => [[...$ring, $ring[0]]]];
        $json = json_encode($polygon);

        $r = DB::selectOne('
            WITH p AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 7844) AS g)
            SELECT ST_IsValid(g) AS valid, ST_IsValidReason(g) AS reason,
                   ST_Area(epfd_to_mga(g)) AS area_m2
            FROM p', [$json]);

        if (! $r->valid) {
            $reason = (string) $r->reason;
            if (str_contains(strtolower($reason), 'self-intersection') || str_contains(strtolower($reason), 'ring self')) {
                throw new DomainProblem('polygon_self_intersection', 'The area outline crosses itself. Move the pins so the edges do not cross.', extra: ['reason' => $reason]);
            }
            throw new DomainProblem('polygon_invalid', 'The area shape is not valid: '.$reason, extra: ['reason' => $reason]);
        }

        $area = (float) $r->area_m2;
        if ($area < (float) $cfg['min_area_m2']) {
            throw new DomainProblem('polygon_too_small', 'The area is too small. Draw a larger area.');
        }
        if ($area > (float) $cfg['max_area_km2'] * 1_000_000) {
            throw new DomainProblem('polygon_too_large', "Areas can be at most {$cfg['max_area_km2']} km². Split it into smaller areas.");
        }

        if ($pickup !== null) {
            $this->assertWithinPickupRadius($polygon, (float) $pickup['lng'], (float) $pickup['lat']);
        }

        return $polygon;
    }

    /** The 20 km rule: the area must be at least PARTLY within the radius of the pickup point. */
    public function assertWithinPickupRadius(array $polygon, float $lng, float $lat): void
    {
        $km = (float) config('epfd.areas.pickup_radius_km');
        $ok = (bool) DB::selectOne('
            WITH p AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 7844) AS g),
                 k AS (SELECT ST_SetSRID(ST_MakePoint(?, ?), 7844) AS pt)
            SELECT ST_DWithin(ST_Transform(p.g, epfd_mga_srid(k.pt)), ST_Transform(k.pt, epfd_mga_srid(k.pt)), ?) AS ok
            FROM p, k', [json_encode($polygon), $lng, $lat, $km * 1000])->ok;

        if (! $ok) {
            throw new DomainProblem('area_outside_pickup_radius', "Every area must be at least partly within {$km} km of the pickup location.");
        }
    }
}
