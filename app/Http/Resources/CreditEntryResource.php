<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CreditLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditLedger */
class CreditEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_cents' => $this->amount_cents,
            'balance_after_cents' => $this->balance_after_cents,
            'reason' => $this->reason,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
