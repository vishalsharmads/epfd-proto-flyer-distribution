<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Run */
class RunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'campaign_id' => $this->campaign_id,
            'campaign_area_id' => $this->campaign_area_id,
            'name' => $this->name,
            'status' => $this->status,
            'geometry' => $this->geoJson(),
            'flyers' => $this->flyers,
            'houses' => $this->houses,
            'units' => $this->units,
            'distance_km' => (float) $this->distance_km,
            'pay_cents' => $this->pay_cents,
            'deadline' => $this->deadline?->toDateString(),
            'distributor_id' => $this->distributor_id,
            'coverage_pct' => $this->coverage_pct,
            'published_at' => $this->published_at?->toIso8601String(),
            'allocated_at' => $this->allocated_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
