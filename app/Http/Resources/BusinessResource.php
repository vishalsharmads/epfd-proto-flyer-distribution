<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Business */
class BusinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'abn' => $this->abn,
            'industry' => $this->industry,
            'address' => $this->address,
            'state' => $this->state,
            'status' => $this->status,
            'can_pay' => $this->canPay(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'status_note' => $this->when($request->user()?->isStaff() ?? false, $this->status_note),
            'credit_balance_cents' => $this->creditBalanceCents(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
