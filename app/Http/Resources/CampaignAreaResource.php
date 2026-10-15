<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CampaignArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CampaignArea */
class CampaignAreaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'draw_mode' => $this->draw_mode,
            'suburb_id' => $this->suburb_id,
            'geometry' => $this->geoJson(),
            'houses' => $this->houses,
            'units' => $this->units,
            'distance_km' => (float) $this->distance_km,
            'fee_cents' => $this->fee_cents,
        ];
    }
}
