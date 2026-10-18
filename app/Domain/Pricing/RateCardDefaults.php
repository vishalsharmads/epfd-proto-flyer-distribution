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
            'design' => self::design(),
        ];
    }

    /**
     * Design Studio (graphic design) — PLACEHOLDER prices, ex GST, AUD; needs EPFD sign-off.
     * Price = package price for the design type (× (1 + double_sided_pct) when a two-sided design of a
     * type that offers sides) + add-ons − promo + GST. `revisions` 99 = unlimited.
     * Types and packages are admin-managed (Rate card → Design). Per type: `print_categories` = print categories
     * whose "Get it designed" link opens this service; `default_product` = print product used by "Print this
     * design" when the customer chose none.
     *
     * @return array<string, mixed>
     */
    public static function design(): array
    {
        return [
            'double_sided_pct' => 40,
            'types' => self::designTypes(),
            'packages' => [
                'basic' => ['label' => 'Basic', 'revisions' => 1, 'turnaround_days' => 5, 'description' => 'One concept from your brief, 1 revision.'],
                'standard' => ['label' => 'Standard', 'revisions' => 3, 'turnaround_days' => 3, 'description' => 'Two concepts, 3 revisions, faster turnaround.'],
                'premium' => ['label' => 'Premium', 'revisions' => 99, 'turnaround_days' => 2, 'description' => 'Senior designer, three concepts, unlimited revisions.'],
            ],
            'addons' => [
                'rush' => ['label' => 'Rush 48-hour turnaround', 'pct' => 50],
                'extra_revision' => ['label' => 'Extra revision', 'fixed' => 35.00],
                'source_files' => ['label' => 'Editable source files (AI/INDD/PSD)', 'fixed' => 49.00],
                'stock_imagery' => ['label' => 'Licensed stock imagery', 'fixed' => 25.00],
            ],
            'rush_turnaround_days' => 2,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function designTypes(): array
    {
        return [
            'flyer' => [
                'label' => 'Flyer design', 'sides_option' => true, 'icon' => 'file-text',
                'description' => 'Eye-catching flyers built for letterbox drops — headline, offer and call to action that get noticed.',
                'print_categories' => ['flyer', 'poster', 'banner', 'magnet'], 'default_product' => 'a5-flyers',
                'prices' => ['basic' => 99, 'standard' => 179, 'premium' => 299],
            ],
            'brochure' => [
                'label' => 'Brochure design', 'sides_option' => false, 'icon' => 'book-open',
                'description' => 'Folded brochures that tell your story across panels, ready for print and distribution.',
                'print_categories' => ['brochure', 'catalogue'], 'default_product' => 'a4-folded-brochures',
                'prices' => ['basic' => 249, 'standard' => 399, 'premium' => 599],
            ],
            'business_card' => [
                'label' => 'Business card design', 'sides_option' => true, 'icon' => 'credit-card',
                'description' => 'Professional business cards that match your brand and print perfectly.',
                'print_categories' => ['business_card'], 'default_product' => 'business-cards',
                'prices' => ['basic' => 79, 'standard' => 129, 'premium' => 199],
            ],
            'presentation_folder' => [
                'label' => 'Presentation folder design', 'sides_option' => false, 'icon' => 'folder-open',
                'description' => 'Branded presentation folders with pocket and business-card slot artwork.',
                'print_categories' => [], 'default_product' => null,
                'prices' => ['basic' => 299, 'standard' => 449, 'premium' => 699],
            ],
            'stationery' => [
                'label' => 'Stationery set design', 'sides_option' => false, 'icon' => 'mail',
                'description' => 'A matching set: letterhead, compliments slip, envelope and business card.',
                'print_categories' => ['business_card'], 'default_product' => 'business-cards',
                'prices' => ['basic' => 199, 'standard' => 349, 'premium' => 549],
            ],
        ];
    }
}
