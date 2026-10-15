<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'email_verified' => $this->email_verified_at !== null,
            'is_distributor' => (bool) $this->is_distributor,
            'roles' => $this->getRoleNames()->values()->all(),
            'business' => BusinessResource::make($this->whenLoaded('business')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
