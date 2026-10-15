<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Geometry columns are hidden from serialisation; select them as GeoJSON with
 * ->withGeoJson('geom') which adds a `geom_geojson` attribute (decoded via geoJson()).
 */
trait HasGeometry
{
    public function initializeHasGeometry(): void
    {
        $this->hidden = array_merge($this->hidden, property_exists($this, 'geometryColumns') ? $this->geometryColumns : ['geom']);
    }

    public function scopeWithGeoJson(Builder $query, string $column = 'geom', int $precision = 6): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        return $query->selectRaw("ST_AsGeoJSON({$this->getTable()}.{$column}, {$precision}) AS {$column}_geojson");
    }

    /** @return array<string, mixed>|null */
    public function geoJson(string $column = 'geom'): ?array
    {
        $raw = $this->getAttributes()[$column.'_geojson'] ?? null;
        if ($raw === null && $this->exists) {
            $raw = static::query()->whereKey($this->getKey())->selectRaw("ST_AsGeoJSON({$column}, 6) AS g")->value('g');
        }

        return $raw ? json_decode((string) $raw, true) : null;
    }
}
