<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Campaign */
class CampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'status' => $this->status,
            'step' => $this->step,
            'business_id' => $this->business_id,
            'include_houses' => $this->include_houses,
            'include_units' => $this->include_units,
            'coverage' => $this->coverage,
            'material' => $this->material,
            'pickup_address' => $this->pickup_address,
            'pickup' => $this->geoJson('pickup_geom'),
            'available_from' => $this->available_from?->toDateString(),
            'notes' => $this->notes,
            'promo_code' => $this->promo_code,
            'houses' => $this->houses,
            'units' => $this->units,
            'letterboxes' => $this->letterboxes,
            'distance_km' => (float) $this->distance_km,
            'subtotal_cents' => $this->subtotal_cents,
            'gst_cents' => $this->gst_cents,
            'total_cents' => $this->total_cents,
            'pricing' => $this->pricing,
            'areas' => CampaignAreaResource::collection($this->whenLoaded('areas')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
