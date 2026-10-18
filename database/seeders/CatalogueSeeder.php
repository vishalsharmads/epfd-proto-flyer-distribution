<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\RateCardDefaults;
use App\Models\PickupPoint;
use App\Models\PrintCategory;
use App\Models\PrintOption;
use App\Models\PrintProduct;
use App\Models\PromoCode;
use App\Models\RateCard;
use App\Support\Geo;
use Illuminate\Database\Seeder;

/**
 * Rate card, print catalogue + price matrix, promo codes and pickup points.
 * Print matrix: per-piece price (ex GST) = from_price × gsm factor × sides factor × quantity factor.
 * The catalogue is admin-managed (Admin → Print products): existing categories and products are never
 * overwritten, only missing ones are created.
 */
class CatalogueSeeder extends Seeder
{
    public const QUANTITIES = [100, 250, 500, 1000, 1500, 2500, 5000, 10000, 20000];

    private const QTY_FACTOR = [100 => 6.0, 250 => 3.4, 500 => 2.2, 1000 => 1.6, 1500 => 1.4, 2500 => 1.25, 5000 => 1.12, 10000 => 1.05, 20000 => 1.0];

    private const GSM_FACTOR = [115 => 1.0, 150 => 1.15, 170 => 1.25, 250 => 1.6];

    /** Matt/gloss rules: 115gsm is gloss-only (thin matt stock is not offered). */
    private const FINISH_RULES = [115 => ['gloss'], 150 => ['matt', 'gloss'], 170 => ['matt', 'gloss'], 250 => ['matt', 'gloss']];

    public function run(): void
    {
        // Idempotent: only seed the default card when no rate card exists yet (admin edits create new versions).
        if (! RateCard::query()->exists()) {
            RateCard::create(['name' => 'Standard rate card 2026', 'is_active' => true, 'data' => RateCardDefaults::data()]);
        }

        $categories = [
            ['flyer', 'Flyers', 'file-text'], ['brochure', 'Brochures', 'book-open'], ['magnet', 'Magnets', 'magnet'],
            ['business_card', 'Business cards', 'credit-card'], ['poster', 'Posters', 'image'], ['banner', 'Banners', 'flag'],
            ['catalogue', 'Catalogues', 'newspaper'],
        ];
        foreach ($categories as $i => [$slug, $name, $icon]) {
            PrintCategory::firstOrCreate(['slug' => $slug], ['name' => $name, 'icon' => $icon, 'sort' => $i]);
        }

        $products = [
            // slug, name, category, size, w, h, from $/pc, gsm, distributable material, sort
            ['a4-flyers', 'A4 Flyers', 'flyer', '210 × 297 mm', 210, 297, 0.07, [115, 150, 170, 250], 'folded'],
            ['a5-flyers', 'A5 Flyers', 'flyer', '148 × 210 mm', 148, 210, 0.04, [115, 150, 170, 250], 'a5'],
            ['a6-flyers', 'A6 Flyers', 'flyer', '105 × 148 mm', 105, 148, 0.03, [115, 150, 170, 250], 'standard_dl'],
            ['dl-flyers', 'DL Flyers', 'flyer', '99 × 210 mm', 99, 210, 0.03, [115, 150, 170, 250], 'standard_dl'],
            ['a3-folded-brochures', 'A3 Folded Brochures', 'brochure', 'A3 folded to A4', 297, 420, 0.13, [150, 170, 250], 'folded'],
            ['a4-folded-brochures', 'A4 Folded Brochures', 'brochure', 'A4 folded to A5/DL', 210, 297, 0.08, [150, 170, 250], 'folded'],
            ['a5-folded-brochures', 'A5 Folded Brochures', 'brochure', 'A5 folded to A6', 148, 210, 0.05, [150, 170, 250], 'folded'],
            ['magnets', 'Magnets', 'magnet', '90 × 55 mm, 0.6 mm', 90, 55, 0.09, [250], 'magnet'],
            ['business-cards', 'Business Cards', 'business_card', '90 × 55 mm', 90, 55, 0.05, [250], null],
            ['a2-posters', 'A2 Posters', 'poster', '420 × 594 mm', 420, 594, 1.20, [150, 170, 250], null],
            ['pull-up-banners', 'Pull-up Banners', 'banner', '850 × 2000 mm', 850, 2000, 45.00, [250], null],
            ['a4-catalogues', 'A4 Catalogues (8pp)', 'catalogue', '210 × 297 mm, 8 pages', 210, 297, 0.55, [115, 150, 170], 'booklet'],
        ];

        foreach ($products as $i => [$slug, $name, $cat, $size, $w, $h, $from, $gsms, $material]) {
            if (PrintProduct::where('slug', $slug)->exists()) {
                continue;
            }
            $qtys = $cat === 'banner' ? [1, 2, 5, 10, 20] : ($cat === 'poster' ? [10, 25, 50, 100, 250, 500] : self::QUANTITIES);
            $sidesOffered = in_array($cat, ['banner', 'magnet'], true) ? [1] : [1, 2];
            $finishes = [];
            foreach ($gsms as $g) {
                $finishes[(string) $g] = $cat === 'magnet' || $cat === 'banner' ? ['matt'] : self::FINISH_RULES[$g];
            }
            $p = PrintProduct::create([
                'slug' => $slug, 'name' => $name, 'category' => $cat, 'size_label' => $size, 'width_mm' => $w, 'height_mm' => $h,
                'bleed_mm' => $cat === 'banner' ? 10 : 3,
                'from_price_cents' => (int) round($from * 100),
                'gsm_options' => $gsms, 'sides_options' => $sidesOffered, 'finish_options' => $finishes, 'quantity_options' => $qtys,
                'distributable' => $material !== null, 'distribution_material' => $material,
                'turnaround_days' => $cat === 'banner' ? 5 : 3, 'sort' => $i, 'is_active' => true,
            ]);
            $rows = [];
            foreach ($gsms as $g) {
                foreach ($sidesOffered as $sides) {
                    foreach ($qtys as $q) {
                        $qf = self::QTY_FACTOR[$q] ?? max(1.0, 3.0 - log10($q) * 0.6);
                        $unit = $from * self::GSM_FACTOR[$g] / self::GSM_FACTOR[$gsms[0]] * ($sides === 2 ? 1.2 : 1.0) * $qf;
                        $rows[] = ['print_product_id' => $p->id, 'gsm' => $g, 'sides' => $sides, 'quantity' => $q, 'unit_price_hc' => (int) round($unit * 10000)];
                    }
                }
            }
            PrintOption::insert($rows);
        }

        PromoCode::updateOrCreate(['code' => 'WELCOME10'], ['description' => '10% off your first order', 'type' => 'percent', 'value' => 10, 'service' => 'any', 'is_active' => true]);
        PromoCode::updateOrCreate(['code' => 'PRINT25'], ['description' => '$25 off print orders over $150', 'type' => 'fixed', 'value' => 2500, 'service' => 'print', 'min_subtotal_cents' => 15000, 'is_active' => true]);
        PromoCode::updateOrCreate(['code' => 'EXPIRED5'], ['description' => 'Expired example', 'type' => 'percent', 'value' => 5, 'service' => 'any', 'ends_at' => now()->subMonth(), 'is_active' => true]);

        $pickups = [
            ['EPFD Rydalmere (HQ)', 'Y-03, 257 Victoria Road, Rydalmere NSW 2116', 'NSW', 151.0365, -33.8126],
            ['EPFD Wollongong depot', '12 Crown Street, Wollongong NSW 2500', 'NSW', 150.8931, -34.4250],
            ['EPFD Newcastle depot', '45 Hunter Street, Newcastle NSW 2300', 'NSW', 151.7790, -32.9270],
            ['EPFD Richmond depot', '220 Swan Street, Richmond VIC 3121', 'VIC', 144.9990, -37.8250],
            ['EPFD New Farm depot', '700 Brunswick Street, New Farm QLD 4005', 'QLD', 153.0450, -27.4670],
        ];
        foreach ($pickups as [$name, $addr, $state, $lng, $lat]) {
            PickupPoint::updateOrCreate(['name' => $name], ['address' => $addr, 'state' => $state, 'geom' => Geo::point($lng, $lat), 'opening_hours' => 'Mon–Fri 8:00am–5:00pm', 'is_active' => true]);
        }
    }
}
