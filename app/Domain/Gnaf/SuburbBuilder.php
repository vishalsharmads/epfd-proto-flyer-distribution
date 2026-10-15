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
 * Official Geoscape Localities (matched on LOCALITY_PID) or ABS SAL (matched on name) boundaries replace
 * them via `suburbs:import` (boundary_source='official').
 *
 * Suburbs are keyed by G-NAF LOCALITY_PID: one state can have several localities with the same name.
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
            $this->adoptLegacyRows($state);
            $total += DB::affectingStatement("
                WITH g AS (
                    SELECT locality_pid, state,
                           mode() WITHIN GROUP (ORDER BY locality) AS locality,
                           epfd_mga_srid(ST_Collect(geom)) AS srid,
                           mode() WITHIN GROUP (ORDER BY postcode) AS postcode
                    FROM addresses WHERE state = ? AND locality_pid IS NOT NULL
                    GROUP BY locality_pid, state
                    HAVING count(*) >= ?
                ),
                pts AS (
                    SELECT g.locality_pid, g.state, g.locality, g.postcode,
                           ST_Collect(DISTINCT ST_SnapToGrid(ST_Transform(a.geom, g.srid), 15)) AS c
                    FROM g JOIN addresses a ON a.locality_pid = g.locality_pid
                    GROUP BY g.locality_pid, g.state, g.locality, g.postcode
                ),
                shaped AS (
                    SELECT locality_pid, state, locality, postcode,
                           ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(
                               ST_SimplifyPreserveTopology(ST_Buffer(ST_ConcaveHull(c, ?), ?), ?), 7844)), 3)) AS b
                    FROM pts
                )
                INSERT INTO suburbs (locality_pid, name, state, postcode, boundary, centroid, boundary_source, is_serviced, created_at, updated_at)
                SELECT locality_pid, initcap(locality), state, postcode, b, ST_PointOnSurface(b), 'derived', true, now(), now()
                FROM shaped WHERE NOT ST_IsEmpty(b)
                ON CONFLICT (locality_pid) DO UPDATE
                   SET name = EXCLUDED.name, postcode = EXCLUDED.postcode, updated_at = now(),
                       boundary = CASE WHEN suburbs.boundary_source = 'derived' THEN EXCLUDED.boundary ELSE suburbs.boundary END,
                       centroid = CASE WHEN suburbs.boundary_source = 'derived' THEN EXCLUDED.centroid ELSE suburbs.centroid END
            ", [$state, $minPoints, $target, $buffer, $simplify]);
        }
        DB::statement('ANALYZE suburbs');

        return $total;
    }

    /**
     * Suburbs created before they were keyed by locality have no locality_pid. Give each one the pid of the
     * largest same-named locality, so its id (referenced by saved campaign areas) and serviced flag carry over.
     * Where the name was shared by several localities, its boundary blended them all: reset it to derived.
     */
    private function adoptLegacyRows(string $state): void
    {
        DB::update("
            WITH counts AS (
                SELECT upper(locality) AS name, locality_pid, count(*) AS n,
                       count(*) OVER (PARTITION BY upper(locality)) AS localities
                FROM addresses WHERE state = ? AND locality_pid IS NOT NULL
                GROUP BY upper(locality), locality_pid
            ),
            biggest AS (
                SELECT DISTINCT ON (name) name, locality_pid, localities FROM counts ORDER BY name, n DESC, locality_pid
            )
            UPDATE suburbs s
               SET locality_pid = b.locality_pid,
                   boundary_source = CASE WHEN b.localities > 1 THEN 'derived' ELSE s.boundary_source END,
                   boundary_matched_by = CASE WHEN b.localities > 1 THEN NULL ELSE s.boundary_matched_by END
              FROM biggest b
             WHERE s.state = ? AND s.locality_pid IS NULL AND upper(s.name) = b.name
               AND NOT EXISTS (SELECT 1 FROM suburbs t WHERE t.locality_pid = b.locality_pid)
        ", [$state, $state]);
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
            JOIN addresses a ON a.locality_pid = s.locality_pid
            {$where}
            GROUP BY s.id
            ON CONFLICT (suburb_id) DO UPDATE SET houses = EXCLUDED.houses, units = EXCLUDED.units,
                primary_with_units = EXCLUDED.primary_with_units, commercial = EXCLUDED.commercial,
                letterboxes = EXCLUDED.letterboxes, computed_at = EXCLUDED.computed_at
        ", $bindings);

        // Suburbs with no G-NAF locality (renamed/retired since) no longer have addresses to count.
        DB::delete('DELETE FROM suburb_stats st USING suburbs s WHERE st.suburb_id = s.id AND s.locality_pid IS NULL'.($states ? ' AND s.state = ANY(?)' : ''), $bindings);
    }
}
