<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SupportThread;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupportThread */
class SupportThreadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'channel' => $this->channel,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
