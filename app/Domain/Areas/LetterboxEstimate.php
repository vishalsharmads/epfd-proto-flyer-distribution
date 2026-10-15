<?php

declare(strict_types=1);

namespace App\Domain\Areas;

final readonly class LetterboxEstimate
{
    public function __construct(
        public int $houses,
        public int $units,
        public float $distanceKm,
        public string $source,
        public float $elapsedMs = 0.0,
    ) {}

    public function total(): int
    {
        return $this->houses + $this->units;
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /** @return array{houses:int, units:int, total:int, distance_km:float, source:string, estimated:bool, no_letterboxes:bool} */
    public function toArray(): array
    {
        return [
            'houses' => $this->houses,
            'units' => $this->units,
            'total' => $this->total(),
            'distance_km' => round($this->distanceKm, 2),
            'source' => $this->source,
            'estimated' => true,
            'no_letterboxes' => $this->isEmpty(),
        ];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self((int) $a['houses'], (int) $a['units'], (float) $a['distance_km'], (string) $a['source']);
    }
}
