<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Immutable price breakdown. All money is integer cents (AUD). Prices are computed ex GST,
 * GST (10%) is itemised and totals are shown GST-inclusive.
 */
final readonly class Quote
{
    /**
     * @param  list<array{code:string, label:string, quantity:int|float, unit_cents:float, amount_cents:int}>  $lines
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $service,
        public array $lines,
        public int $subtotalCents,
        public int $discountCents,
        public int $gstCents,
        public int $totalCents,
        public float $perItemInclGstCents,
        public array $meta = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'currency' => 'AUD',
            'lines' => $this->lines,
            'discount_cents' => $this->discountCents,
            'subtotal_cents' => $this->subtotalCents,
            'gst_cents' => $this->gstCents,
            'total_cents' => $this->totalCents,
            'per_item_incl_gst_cents' => round($this->perItemInclGstCents, 2),
            'gst_inclusive' => true,
            'meta' => $this->meta,
        ];
    }
}
