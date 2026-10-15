<?php

declare(strict_types=1);

namespace Database\Factories;

/** Small GeoJSON helpers for factories (plausible Sydney geometry). */
final class SydneyGeo
{
    /** Axis-aligned rectangle centred on (lng, lat); half-sizes in degrees. */
    public static function rectangle(float $lng, float $lat, float $dx = 0.002, float $dy = 0.0015): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[
            [$lng - $dx, $lat - $dy], [$lng + $dx, $lat - $dy], [$lng + $dx, $lat + $dy], [$lng - $dx, $lat + $dy], [$lng - $dx, $lat - $dy],
        ]]];
    }
}
