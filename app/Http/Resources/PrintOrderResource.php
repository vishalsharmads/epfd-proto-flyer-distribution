<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PrintOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PrintOrder */
class PrintOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'step' => $this->step,
            'print_product_id' => $this->print_product_id,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'slug' => $this->product->slug]),
            'gsm' => $this->gsm,
            'finish' => $this->finish,
            'sides' => $this->sides,
            'orientation' => $this->orientation,
            'quantity' => $this->quantity,
            'subtotal_cents' => $this->subtotal_cents,
            'gst_cents' => $this->gst_cents,
            'total_cents' => $this->total_cents,
            'pricing' => $this->pricing,
            'promo_code' => $this->promo_code,
            'delivery_name' => $this->delivery_name,
            'delivery_address' => $this->delivery_address,
            'delivery_phone' => $this->delivery_phone,
            'earliest_delivery' => $this->earliest_delivery?->toDateString(),
            'also_distribute' => $this->also_distribute,
            'right_to_print' => $this->right_to_print,
            'vendor' => $this->vendor,
            'tracking_number' => $this->tracking_number,
            'artworks' => ArtworkResource::collection($this->whenLoaded('artworks')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
