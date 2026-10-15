<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Seeded "realistic" rate card (all prices ex GST, AUD). Calibrated against RealRun's public
 * pricing (Houses + units ≈ $0.891 incl GST, Houses only ≈ $1.221, Units only ≈ $3.288).
 * Editable in Admin → Rate card; the active row in `rate_cards` wins over these defaults.
 */
final class RateCardDefaults
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        return [
            'gst_rate' => 0.10,
            'distribution' => [
                'coverage' => [
                    'houses_units' => ['label' => 'Houses + units', 'per_item' => 0.81],
                    'houses_only' => ['label' => 'Houses only', 'per_item' => 1.11],
                    'units_only' => ['label' => 'Units only', 'per_item' => 2.989],
                ],
                'materials' => [
                    'standard_dl' => ['label' => 'Standard DL', 'add' => 0.00],
                    'a5' => ['label' => 'A5', 'add' => 0.00],
                    'folded' => ['label' => 'Folded', 'add' => 0.01],
                    'envelope' => ['label' => 'Envelope', 'add' => 0.02],
                    'magnet' => ['label' => 'Magnet', 'add' => 0.04],
                    'booklet' => ['label' => 'Booklet', 'add' => 0.06],
                    'double_up' => ['label' => 'Double Up Flyer', 'add' => 0.08],
                    'magazine' => ['label' => 'Magazine', 'add' => 0.10],
                ],
                'quantity_bands' => [
                    ['min' => 0, 'discount_pct' => 0],
                    ['min' => 5000, 'discount_pct' => 3],
                    ['min' => 10000, 'discount_pct' => 5],
                    ['min' => 20000, 'discount_pct' => 8],
                ],
                'minimum_fee' => 20.00,
                'distributor_pay_per_item' => 0.32,
            ],
            'print' => [
                'minimum_order' => 25.00,
                'turnaround_days' => 3,
                'finishes' => [
                    'matt' => ['label' => 'Matt', 'pct' => 0],
                    'gloss' => ['label' => 'Gloss', 'pct' => 5],
                ],
                'addons' => [
                    'express' => ['label' => 'Express production (next business day)', 'pct' => 25],
                    'hard_proof' => ['label' => 'Printed hard-copy proof', 'fixed' => 15.00],
                ],
            ],
        ];
    }
}
