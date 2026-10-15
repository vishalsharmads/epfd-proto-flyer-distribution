<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'business_id' => $this->business_id,
            'payment_id' => $this->payment_id,
            'invoiceable_type' => $this->invoiceable_type,
            'invoiceable_id' => $this->invoiceable_id,
            'lines' => $this->lines,
            'billing' => $this->billing,
            'subtotal_cents' => $this->subtotal_cents,
            'gst_cents' => $this->gst_cents,
            'total_cents' => $this->total_cents,
            'credits_applied_cents' => $this->credits_applied_cents,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toIso8601String(),
        ];
    }
}
