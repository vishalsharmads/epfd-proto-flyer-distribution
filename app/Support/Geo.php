<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * GeoJSON <-> PostGIS helpers. Client coordinates (WGS84 lon/lat from the browser) are treated as
 * GDA2020 (EPSG:7844) — the ~1.8 m datum offset is negligible for letterbox counting (see README).
 */
final class Geo
{
    public const SRID = 7844;

    /** @param array<string, mixed> $geometry */
    public static function fromGeoJson(array $geometry): Expression
    {
        $json = DB::getPdo()->quote((string) json_encode($geometry));

        return DB::raw('ST_SetSRID(ST_GeomFromGeoJSON('.$json.'), '.self::SRID.')');
    }

    public static function point(float $lng, float $lat): Expression
    {
        return DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%.8F, %.8F), %d)', $lng, $lat, self::SRID));
    }

    /**
     * @param  list<array{0:float,1:float}>  $ring  [lng, lat] pairs (open or closed)
     * @return array{type:string, coordinates: list<list<array{0:float,1:float}>>}
     */
    public static function polygon(array $ring): array
    {
        $ring = array_values($ring);
        if ($ring !== [] && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    /** @return array<string, mixed>|null */
    public static function decode(?string $geojson): ?array
    {
        if ($geojson === null || $geojson === '') {
            return null;
        }
        $g = json_decode($geojson, true);

        return is_array($g) ? $g : null;
    }

    /** Stable hash for caching estimates: rounded coordinates (≈1 m) + options. */
    public static function hash(array $geometry, string ...$extra): string
    {
        $round = static function ($v) use (&$round) {
            return is_array($v) ? array_map($round, $v) : (is_float($v) || is_int($v) ? round((float) $v, 5) : $v);
        };

        return hash('sha256', json_encode($round($geometry['coordinates'] ?? [])).'|'.implode('|', $extra));
    }
}
