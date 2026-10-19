<?php

declare(strict_types=1);

namespace App\Domain\Areas;

use App\Domain\Gnaf\AddressClassifier;
use App\Exceptions\DomainProblem;
use Illuminate\Support\Facades\DB;

/**
 * Splits an area whose estimated walk is over the limit (Admin → Settings, epfd.areas.max_walk_km)
 * into pieces one distributor can walk.
 *
 *   1. By suburb: cut along official suburb boundaries (distributors think in suburbs).
 *   2. By distance: a piece still over the limit is split into k = ceil(walk / (0.9 × limit)) groups of
 *      nearby addresses (ST_ClusterKMeans in MGA metres). Each group's shape is the union of the Voronoi
 *      cells of its addresses, clipped to the piece, so pieces tile the piece without gaps or overlaps.
 *      Repeated (up to 4 levels) until every piece is under the limit.
 *   3. Clean up: pieces with fewer than epfd.areas.split_min_letterboxes letterboxes (or too small to be a
 *      valid area) are merged into the neighbour they share the longest border with.
 *
 * Pieces are cleaned to what PolygonValidator accepts: single polygons, holes filled, ≤ max_vertices.
 */
final class AreaSplitter
{
    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly LetterboxEstimator $estimator,
        private readonly AddressClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $geometry  GeoJSON Polygon
     * @return list<array{name: string, geometry: array<string, mixed>, estimate: LetterboxEstimate}>
     */
    public function split(array $geometry, bool $includeHouses, bool $includeUnits, string $name = 'Area'): array
    {
        $limitKm = (float) config('epfd.areas.max_walk_km', 30);

        $bySuburb = $this->bySuburb($geometry);
        $queue = count($bySuburb) > 1 ? array_map(fn ($p) => [$p['name'], $p['geometry'], 0], $bySuburb) : [[$name, $geometry, 0]];

        /** @var list<array{name: string, geometry: array<string, mixed>, estimate: ?LetterboxEstimate}> $pieces */
        $pieces = [];
        while ($queue) {
            [$pieceName, $pieceGeometry, $depth] = array_shift($queue);
            $estimate = $this->estimate($pieceGeometry, $includeHouses, $includeUnits);
            if ($estimate && $estimate->distanceKm > $limitKm && $depth < self::MAX_DEPTH) {
                $k = max(2, (int) ceil($estimate->distanceKm / ($limitKm * 0.9)));
                $parts = $this->byDistance($pieceGeometry, $k);
                if (count($parts) > 1) {
                    foreach ($parts as $part) {
                        $queue[] = [$pieceName, $part, $depth + 1];
                    }

                    continue;
                }
            }
            $pieces[] = ['name' => $pieceName, 'geometry' => $pieceGeometry, 'estimate' => $estimate];
        }

        $pieces = $this->mergeSmall($pieces, $includeHouses, $includeUnits);

        return $this->named(array_values(array_filter($pieces, fn ($p) => $p['estimate'] !== null)));
    }

    /** Estimate without the pickup rule; null when the piece is not a valid area on its own (sliver, too small). */
    private function estimate(array $geometry, bool $houses, bool $units): ?LetterboxEstimate
    {
        try {
            return $this->estimator->estimate($geometry, $houses, $units)['estimate'];
        } catch (DomainProblem) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return list<array{name: string, geometry: array<string, mixed>}>
     */
    private function bySuburb(array $geometry): array
    {
        $rows = DB::select('
            WITH poly AS (SELECT ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)) AS g),
            cut AS (
                SELECT s.name, ST_CollectionExtract(ST_Intersection(s.boundary, p.g), 3) AS g
                FROM suburbs s, poly p WHERE s.boundary && p.g AND ST_Intersects(s.boundary, p.g)
                UNION ALL
                -- whatever no suburb covers stays a piece too
                SELECT NULL, ST_CollectionExtract(ST_Difference(p.g, coalesce((SELECT ST_Union(s.boundary) FROM suburbs s WHERE s.boundary && p.g), ST_GeomFromText(\'POLYGON EMPTY\', 7844))), 3)
                FROM poly p
            )
            SELECT name, ST_AsGeoJSON((ST_Dump(g)).geom, 7) AS gj FROM cut WHERE NOT ST_IsEmpty(g)
        ', [json_encode($geometry)]);

        $out = [];
        foreach ($rows as $r) {
            if ($clean = $this->clean((string) $r->gj)) {
                $out[] = ['name' => $r->name ?? 'Area', 'geometry' => $clean];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return list<array<string, mixed>>
     */
    private function byDistance(array $geometry, int $k): array
    {
        $countable = $this->classifier->countableSql('a');
        $rows = DB::select("
            WITH poly AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 7844) AS g),
            z AS (SELECT g, epfd_mga_srid(g) AS srid FROM poly),
            zm AS (SELECT g, srid, ST_Transform(g, srid) AS gm FROM z),
            pts AS (
                SELECT DISTINCT ON (ST_SnapToGrid(a.geom, 0.000001)) ST_Transform(a.geom, zm.srid) AS p
                FROM addresses a, zm WHERE ST_Intersects(a.geom, zm.g) AND {$countable}
                ORDER BY ST_SnapToGrid(a.geom, 0.000001)
            ),
            cl AS (SELECT p, ST_ClusterKMeans(p, ?) OVER () AS cid FROM pts),
            vor AS (SELECT (ST_Dump(ST_VoronoiPolygons(ST_Collect(pts.p), 0, ST_Expand(ST_Envelope(zm.gm), 200)))).geom AS cell FROM pts, zm GROUP BY zm.gm),
            lab AS (SELECT vor.cell, cl.cid FROM vor JOIN cl ON ST_Intersects(vor.cell, cl.p)),
            grp AS (SELECT cid, ST_Union(cell) AS u FROM lab GROUP BY cid)
            SELECT ST_AsGeoJSON((ST_Dump(ST_CollectionExtract(ST_Transform(ST_Intersection(grp.u, zm.gm), 7844), 3))).geom, 7) AS gj
            FROM grp, zm
        ", [json_encode($geometry), $k]);

        return array_values(array_filter(array_map(fn ($r) => $this->clean((string) $r->gj), $rows)));
    }

    /**
     * Make a piece acceptable to PolygonValidator and to the map editor: one polygon, no holes, at most
     * max_vertices vertices, and a simple outline at the precision it is saved with (7 decimals).
     *
     * Cutting along suburb borders and Voronoi cells leaves repeated points and centimetre-long stubs. PostGIS
     * accepts those as valid, but the editor (and Terra Draw) see the stubs as the outline crossing itself and
     * refuse to edit the area. Pieces are simplified to ~1 m (points closer than that merge; no letterbox area needs
     * finer detail) and the outline must be simple.
     *
     * @return array<string, mixed>|null
     */
    private function clean(string $geojson): ?array
    {
        $max = (int) config('epfd.areas.max_vertices', 500);
        foreach ([0.00001, 0.00002, 0.00005, 0.0001, 0.0002, 0.0005, 0.001] as $tolerance) {
            $row = DB::selectOne('
                WITH g AS (SELECT ST_MakePolygon(ST_ExteriorRing(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844))) AS g),
                s AS (SELECT ST_SimplifyPreserveTopology(g, ?::float8) AS g FROM g),
                c AS (SELECT ST_RemoveRepeatedPoints(ST_SnapToGrid(g, 0.0000001), 0.00001) AS g FROM s)
                SELECT ST_AsGeoJSON(g, 7) AS gj, ST_NPoints(g) AS n,
                       ST_IsValid(g) AND ST_IsSimple(ST_ExteriorRing(g)) AS ok
                FROM c WHERE NOT ST_IsEmpty(g) AND GeometryType(g) = \'POLYGON\'
            ', [$geojson, $tolerance]);
            if ($row && $row->ok && $row->n - 1 <= $max) {
                return json_decode((string) $row->gj, true);
            }
        }

        return null;
    }

    /**
     * Merge pieces below the letterbox minimum (or with no estimate) into the neighbour with the longest shared border.
     *
     * @param  list<array{name: string, geometry: array<string, mixed>, estimate: ?LetterboxEstimate}>  $pieces
     * @return list<array{name: string, geometry: array<string, mixed>, estimate: ?LetterboxEstimate}>
     */
    private function mergeSmall(array $pieces, bool $houses, bool $units): array
    {
        $min = (int) config('epfd.areas.split_min_letterboxes', 50);
        for ($guard = 0; $guard < 1000 && count($pieces) > 1; $guard++) {
            // Smallest first, so slivers fold into real pieces before small pieces merge with each other.
            $small = null;
            $smallest = PHP_INT_MAX;
            foreach ($pieces as $i => $p) {
                $total = $p['estimate']?->total() ?? -1;
                if ($total < $min && $total < $smallest) {
                    [$small, $smallest] = [$i, $total];
                }
            }
            if ($small === null) {
                break;
            }

            $best = null;
            $bestShared = 0.0;
            foreach ($pieces as $j => $p) {
                if ($j === $small) {
                    continue;
                }
                $shared = (float) DB::selectOne('SELECT ST_Length(ST_Intersection(ST_Boundary(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)), ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844), 0.00001))) AS l', [json_encode($pieces[$small]['geometry']), json_encode($p['geometry'])])->l;
                if ($shared > $bestShared) {
                    [$best, $bestShared] = [$j, $shared];
                }
            }

            if ($best === null) {
                // No neighbour: keep it if it is a valid area, otherwise drop it.
                if (! $pieces[$small]['estimate'] || $pieces[$small]['estimate']->total() === 0) {
                    array_splice($pieces, $small, 1);

                    continue;
                }
                break;
            }

            $union = DB::selectOne('SELECT ST_AsGeoJSON(ST_Buffer(ST_Buffer(ST_Union(ST_SetSRID(ST_GeomFromGeoJSON(?), 7844), ST_SetSRID(ST_GeomFromGeoJSON(?), 7844)), 0.000005), -0.000005), 7) AS gj', [json_encode($pieces[$small]['geometry']), json_encode($pieces[$best]['geometry'])])->gj;
            $merged = $this->clean((string) $union);
            if (! $merged) {
                array_splice($pieces, $small, 1);

                continue;
            }
            $pieces[$best] = ['name' => $pieces[$best]['name'], 'geometry' => $merged, 'estimate' => $this->estimate($merged, $houses, $units)];
            array_splice($pieces, $small, 1);
        }

        return $pieces;
    }

    /**
     * Number pieces that share a name: "Sydney 1", "Sydney 2".
     *
     * @param  list<array{name: string, geometry: array<string, mixed>, estimate: LetterboxEstimate}>  $pieces
     * @return list<array{name: string, geometry: array<string, mixed>, estimate: LetterboxEstimate}>
     */
    private function named(array $pieces): array
    {
        $totals = array_count_values(array_column($pieces, 'name'));
        $seen = [];

        return array_map(function ($p) use ($totals, &$seen) {
            if ($totals[$p['name']] > 1) {
                $seen[$p['name']] = ($seen[$p['name']] ?? 0) + 1;
                $p['name'] .= ' '.$seen[$p['name']];
            }

            return $p;
        }, $pieces);
    }
}
