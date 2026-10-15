<?php

declare(strict_types=1);

namespace App\Domain\Areas;

use App\Domain\Gnaf\AddressClassifier;
use Illuminate\Support\Facades\DB;

/**
 * Counts letterboxes from G-NAF address points with one PostGIS query:
 *   ST_Intersects(addresses.geom, polygon) via the GIST index, grouped by kind, with the
 *   AddressClassifier anti-double-count / commercial rules applied.
 *
 * Walking-distance heuristic (documented estimate, not routing):
 *   1. Per street (street_locality_pid), de-duplicate points (units share a geocode), order by
 *      street number and sum consecutive distances in the MGA2020 zone (metres). Gaps longer than
 *      estimator.max_segment_gap_m are treated as a break (the walker would not walk them).
 *   2. Order street centroids by geohash and add the distance between consecutive streets,
 *      each connector capped at estimator.connector_cap_m.
 */
final class GnafLetterboxSource implements LetterboxSource
{
    public function __construct(private readonly AddressClassifier $classifier) {}

    public function name(): string
    {
        return 'gnaf';
    }

    public function estimate(array $polygon, bool $includeHouses, bool $includeUnits): LetterboxEstimate
    {
        $t = microtime(true);
        if (! $includeHouses && ! $includeUnits) {
            return new LetterboxEstimate(0, 0, 0.0, $this->name());
        }

        $row = DB::selectOne($this->sql($includeHouses, $includeUnits), [
            json_encode($polygon),
            (float) config('epfd.estimator.connector_cap_m', 250),
            (float) config('epfd.estimator.max_segment_gap_m', 400),
        ]);

        return new LetterboxEstimate(
            (int) $row->houses,
            (int) $row->units,
            round(((float) $row->distance_m) / 1000, 2),
            $this->name(),
            (microtime(true) - $t) * 1000,
        );
    }

    public function sql(bool $includeHouses, bool $includeUnits): string
    {
        $countable = $this->classifier->countableSql('a');
        $kind = $this->classifier->kindForCountSql('a');
        $kinds = implode(',', array_filter([$includeHouses ? "'house'" : null, $includeUnits ? "'unit'" : null]));

        return "
            WITH poly AS (
                SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 7844) AS g
            ), z AS (
                SELECT g, epfd_mga_srid(g) AS srid FROM poly
            ), pts AS (
                SELECT {$kind} AS kind, a.street_locality_pid AS street, a.number_first AS num, a.geom
                FROM addresses a, z
                WHERE ST_Intersects(a.geom, z.g) AND {$countable}
            ), sel AS (
                SELECT * FROM pts WHERE kind IN ({$kinds})
            ), counts AS (
                SELECT count(*) FILTER (WHERE kind = 'house') AS houses,
                       count(*) FILTER (WHERE kind = 'unit') AS units
                FROM sel
            ), dp AS (
                -- one drop point per street number (units in a block share the walk)
                SELECT DISTINCT ON (street, coalesce(num::text, ST_AsText(geom))) street, num, ST_Transform(geom, (SELECT srid FROM z)) AS p
                FROM sel ORDER BY street, coalesce(num::text, ST_AsText(geom))
            ), seg AS (
                -- walk each side of the street (odd / even numbers) in number order
                SELECT street, ST_Distance(p, lag(p) OVER (PARTITION BY street, coalesce(num % 2, 2) ORDER BY num NULLS LAST, ST_X(p), ST_Y(p))) AS d
                FROM dp
            ), cent AS (
                SELECT street, ST_Centroid(ST_Collect(p)) AS c FROM dp GROUP BY street
            ), conn AS (
                SELECT LEAST(ST_Distance(c, lag(c) OVER (ORDER BY ST_GeoHash(ST_Transform(c, 4326), 10))), ?) AS d FROM cent
            )
            SELECT counts.houses, counts.units,
                   coalesce((SELECT sum(d) FROM seg WHERE d <= ?), 0)
                 + coalesce((SELECT sum(d) FROM conn), 0) AS distance_m
            FROM counts";
    }
}
