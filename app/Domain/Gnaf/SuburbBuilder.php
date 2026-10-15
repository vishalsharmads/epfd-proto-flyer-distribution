<?php

declare(strict_types=1);

namespace App\Domain\Gnaf;

use Illuminate\Support\Facades\DB;

/**
 * Suburb boundaries for the "Enter a suburb" draw mode.
 *
 * G-NAF has no polygons, so by default each locality's boundary is DERIVED from its own address
 * points: snap to a 15 m grid (de-dup), ST_ConcaveHull, buffer, simplify — all in the locality's MGA
 * zone (metres) — then back to GDA2020. These are approximate and flagged boundary_source='derived'.
 * Official ABS SAL / Geoscape boundaries can replace them via `suburbs:import` (boundary_source='official').
 */
final class SuburbBuilder
{
    public function __construct(private readonly AddressClassifier $classifier) {}

    /** @param list<string> $states */
    public function rebuildDerived(array $states): int
    {
        $cfg = (array) config('epfd.suburbs');
        $target = (float) ($cfg['concave_target'] ?? 0.35);
        $buffer = (int) ($cfg['buffer_m'] ?? 60);
        $simplify = (int) ($cfg['simplify_m'] ?? 25);
        $minPoints = (int) ($cfg['min_points'] ?? 8);
        $total = 0;

        foreach ($states as $state) {
            $total += DB::affectingStatement("
                WITH g AS (
                    SELECT state, locality,
                           epfd_mga_srid(ST_Collect(geom)) AS srid,
                           mode() WITHIN GROUP (ORDER BY postcode) AS postcode
                    FROM addresses WHERE state = ?
                    GROUP BY state, locality
                    HAVING count(*) >= ?
                ),
                pts AS (
                    SELECT g.state, g.locality, g.postcode,
                           ST_Collect(DISTINCT ST_SnapToGrid(ST_Transform(a.geom, g.srid), 15)) AS c
                    FROM g JOIN addresses a ON a.state = g.state AND a.locality = g.locality
                    GROUP BY g.state, g.locality, g.postcode
                ),
                shaped AS (
                    SELECT state, locality, postcode,
                           ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(
                               ST_SimplifyPreserveTopology(ST_Buffer(ST_ConcaveHull(c, ?), ?), ?), 7844)), 3)) AS b
                    FROM pts
                )
                INSERT INTO suburbs (name, state, postcode, boundary, centroid, boundary_source, is_serviced, created_at, updated_at)
                SELECT initcap(locality), state, postcode, b, ST_PointOnSurface(b), 'derived', true, now(), now()
                FROM shaped WHERE NOT ST_IsEmpty(b)
                ON CONFLICT (name, state) DO UPDATE
                   SET boundary = EXCLUDED.boundary, centroid = EXCLUDED.centroid, postcode = EXCLUDED.postcode, updated_at = now()
                   WHERE suburbs.boundary_source = 'derived'
            ", [$state, $minPoints, $target, $buffer, $simplify]);
        }
        DB::statement('ANALYZE suburbs');

        return $total;
    }

    /** @param list<string>|null $states */
    public function refreshStats(?array $states = null): void
    {
        $countable = $this->classifier->countableSql('a');
        $kind = $this->classifier->kindForCountSql('a');
        $where = $states ? 'WHERE s.state = ANY(?)' : '';
        $bindings = $states ? ['{'.implode(',', $states).'}'] : [];

        DB::statement("
            INSERT INTO suburb_stats (suburb_id, houses, units, primary_with_units, commercial, letterboxes, computed_at)
            SELECT s.id,
                   count(*) FILTER (WHERE {$countable} AND {$kind} = 'house'),
                   count(*) FILTER (WHERE {$countable} AND {$kind} = 'unit'),
                   count(*) FILTER (WHERE a.is_primary_with_units),
                   count(*) FILTER (WHERE a.is_commercial),
                   count(*) FILTER (WHERE {$countable}),
                   now()
            FROM suburbs s
            JOIN addresses a ON a.state = s.state AND upper(a.locality) = upper(s.name)
            {$where}
            GROUP BY s.id
            ON CONFLICT (suburb_id) DO UPDATE SET houses = EXCLUDED.houses, units = EXCLUDED.units,
                primary_with_units = EXCLUDED.primary_with_units, commercial = EXCLUDED.commercial,
                letterboxes = EXCLUDED.letterboxes, computed_at = EXCLUDED.computed_at
        ", $bindings);
    }
}
