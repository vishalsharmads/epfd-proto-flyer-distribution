<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'payable_type' => $this->payable_type,
            'payable_id' => $this->payable_id,
            'gateway' => $this->gateway,
            'gateway_ref' => $this->gateway_ref,
            'amount_cents' => $this->amount_cents,
            'credits_applied_cents' => $this->credits_applied_cents,
            'refunded_cents' => $this->refunded_cents,
            'currency' => $this->currency,
            'status' => $this->status,
            'card_brand' => $this->card_brand,
            'card_last4' => $this->card_last4,
            'failure_reason' => $this->failure_reason,
            'billing' => $this->billing,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
