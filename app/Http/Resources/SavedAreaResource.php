<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SavedArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SavedArea */
class SavedAreaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'geometry' => $this->geoJson(),
            'houses' => $this->houses,
            'units' => $this->units,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
