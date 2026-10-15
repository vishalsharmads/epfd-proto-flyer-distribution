<?php

declare(strict_types=1);

namespace App\Domain\Areas;

use App\Exceptions\DomainProblem;

/**
 * STUB — paid-data upgrade path (Geoscape Buildings + Geoscape Land Parcels / Land Use).
 *
 * Why: G-NAF counts addresses, not letterboxes. Geoscape Buildings adds building footprints with
 * attributes (planning zone, residential/commercial flags, number of storeys, dwelling estimate)
 * which let us:
 *   - drop non-residential buildings precisely (instead of the flat-type heuristic),
 *   - count apartment buildings with a shared mail room as one drop point,
 *   - weight walking distance by real frontages.
 *
 * How to implement:
 *   1. Load Buildings (GDA2020 GeoPackage/SHP) into `buildings(geom polygon 7844, building_pid,
 *      planning_zone, primary_land_use, estimated_dwellings, ...)` with a GIST index.
 *   2. Link buildings ↔ G-NAF via the Buildings "ADDRESS_LINK" table (building_pid → address_detail_pid).
 *   3. estimate(): ST_Intersects on buildings, sum residential estimated_dwellings split by
 *      house (1 dwelling) vs unit (>1), fall back to GnafLetterboxSource where no building exists.
 *   4. Set LETTERBOX_SOURCE=geoscape_buildings. Callers do not change.
 */
final class GeoscapeBuildingsLetterboxSource implements LetterboxSource
{
    public function name(): string
    {
        return 'geoscape_buildings';
    }

    public function estimate(array $polygon, bool $includeHouses, bool $includeUnits): LetterboxEstimate
    {
        throw new DomainProblem(
            'letterbox_source_unavailable',
            'The Geoscape Buildings letterbox source is not licensed/configured in this prototype. Set LETTERBOX_SOURCE=gnaf.',
            503,
            'Letterbox source unavailable',
        );
    }
}
