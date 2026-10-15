<?php

declare(strict_types=1);

namespace App\Domain\Areas;

/**
 * Pluggable letterbox counting backend. The default is GnafLetterboxSource (open G-NAF address points).
 * The paid-data upgrade (Geoscape Buildings + Land Use) plugs in here without touching callers —
 * select it with LETTERBOX_SOURCE=geoscape_buildings.
 */
interface LetterboxSource
{
    /**
     * @param  array{type:string, coordinates: array<mixed>}  $polygon  GeoJSON Polygon in EPSG:7844
     */
    public function estimate(array $polygon, bool $includeHouses, bool $includeUnits): LetterboxEstimate;

    public function name(): string;
}
