<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Design\DesignService;
use App\Models\DesignOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DesignOrder */
class DesignOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'design_type' => $this->design_type,
            'package' => $this->package,
            'size' => $this->size,
            'print_product_id' => $this->print_product_id,
            'sides' => $this->sides,
            'brief' => [
                'headline' => $this->headline,
                'key_message' => $this->key_message,
                'call_to_action' => $this->call_to_action,
                'contact_details' => $this->contact_details,
                'brand_colours' => $this->brand_colours,
                'style_notes' => $this->style_notes,
                'target_audience' => $this->target_audience,
                'due_date' => $this->due_date?->toDateString(),
            ],
            'addons' => DesignService::addons($this->resource),
            'revisions_included' => $this->revisions_included,
            'revisions_used' => $this->revisions_used,
            'extra_revisions' => $this->resource->extraRevisions(),
            'subtotal_cents' => $this->subtotal_cents,
            'gst_cents' => $this->gst_cents,
            'total_cents' => $this->total_cents,
            'pricing' => $this->pricing,
            'promo_code' => $this->promo_code,
            'designer_id' => $this->designer_id,
            'print_order_id' => $this->print_order_id,
            'assets' => DesignAssetResource::collection($this->whenLoaded('assets')),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
