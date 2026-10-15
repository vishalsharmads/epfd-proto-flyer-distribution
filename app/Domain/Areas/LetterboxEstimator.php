<?php

declare(strict_types=1);

namespace App\Domain\Areas;

use App\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * Validates a polygon, applies the pickup radius rule, and counts letterboxes through the configured
 * LetterboxSource. Results are cached by a hash of the (rounded) polygon + options.
 */
final class LetterboxEstimator
{
    public function __construct(
        private readonly LetterboxSource $source,
        private readonly PolygonValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $geometry  GeoJSON polygon (client lon/lat treated as EPSG:7844)
     * @param  array{lng: float, lat: float}|null  $pickup
     * @return array{polygon: array<string, mixed>, estimate: LetterboxEstimate, cached: bool, hash: string}
     */
    public function estimate(array $geometry, bool $includeHouses, bool $includeUnits, ?array $pickup = null): array
    {
        $polygon = $this->validator->validate($geometry, $pickup);
        $hash = Geo::hash($polygon, $this->source->name(), $includeHouses ? 'h' : '-', $includeUnits ? 'u' : '-', md5((string) json_encode(config('epfd.gnaf.rules'))));
        $key = 'estimate:'.$hash;

        $cached = true;
        $data = Cache::remember($key, (int) config('epfd.estimator.cache_ttl', 3600), function () use ($polygon, $includeHouses, $includeUnits, &$cached) {
            $cached = false;

            return $this->source->estimate($polygon, $includeHouses, $includeUnits)->toArray();
        });

        return ['polygon' => $polygon, 'estimate' => LetterboxEstimate::fromArray($data), 'cached' => $cached, 'hash' => $hash];
    }

    public function source(): LetterboxSource
    {
        return $this->source;
    }
}
