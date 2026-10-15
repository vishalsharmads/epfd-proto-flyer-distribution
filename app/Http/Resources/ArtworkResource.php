<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Artwork;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Artwork */
class ArtworkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'side' => $this->side,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'width_px' => $this->width_px,
            'height_px' => $this->height_px,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
