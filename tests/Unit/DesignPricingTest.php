<?php

declare(strict_types=1);

use App\Domain\Pricing\PricingService;
use App\Domain\Pricing\RateCardDefaults;
use App\Exceptions\DomainProblem;

function designPricing(?array $card = null): PricingService
{
    return new PricingService($card ?? RateCardDefaults::data());
}

it('prices a single-sided basic flyer at $99 ex GST', function () {
    $q = designPricing()->design(['design_type' => 'flyer', 'package' => 'basic']);
    expect($q->service)->toBe('design')
        ->and($q->subtotalCents)->toBe(9900)
        ->and($q->gstCents)->toBe(990)
        ->and($q->totalCents)->toBe(10890)
        ->and($q->meta['revisions_included'])->toBe(1)
        ->and($q->meta['turnaround_days'])->toBe(5);
});

it('prices every type × package from the rate card', function (string $type, string $package, int $cents) {
    expect(designPricing()->design(['design_type' => $type, 'package' => $package])->subtotalCents)->toBe($cents);
})->with([
    ['flyer', 'standard', 17900], ['flyer', 'premium', 29900],
    ['brochure', 'basic', 24900], ['brochure', 'standard', 39900], ['brochure', 'premium', 59900],
    ['business_card', 'basic', 7900], ['business_card', 'standard', 12900], ['business_card', 'premium', 19900],
    ['presentation_folder', 'basic', 29900], ['presentation_folder', 'standard', 44900], ['presentation_folder', 'premium', 69900],
    ['stationery', 'basic', 19900], ['stationery', 'standard', 34900], ['stationery', 'premium', 54900],
]);

it('adds 40% for a double-sided flyer, but ignores sides for types without a sides option', function () {
    expect(designPricing()->design(['design_type' => 'flyer', 'package' => 'standard', 'sides' => 2])->subtotalCents)->toBe(25060) // 179 × 1.4
        ->and(designPricing()->design(['design_type' => 'flyer', 'package' => 'standard', 'sides' => 2])->meta['sides'])->toBe(2)
        ->and(designPricing()->design(['design_type' => 'brochure', 'package' => 'basic', 'sides' => 2])->subtotalCents)->toBe(24900)
        ->and(designPricing()->design(['design_type' => 'brochure', 'package' => 'basic', 'sides' => 2])->meta['sides'])->toBe(1);
});

it('reports revisions and turnaround per package (premium = unlimited)', function () {
    $std = designPricing()->design(['design_type' => 'flyer', 'package' => 'standard']);
    $pre = designPricing()->design(['design_type' => 'flyer', 'package' => 'premium']);
    expect($std->meta['revisions_included'])->toBe(3)->and($std->meta['turnaround_days'])->toBe(3)
        ->and($pre->meta['revisions_included'])->toBe(99)->and($pre->meta['revisions_unlimited'])->toBeTrue()->and($pre->meta['turnaround_days'])->toBe(2);
});

it('applies rush (+50% of the design line, 48h) and fixed add-ons', function () {
    $q = designPricing()->design(['design_type' => 'flyer', 'package' => 'basic', 'addons' => ['rush', 'extra_revision', 'source_files', 'stock_imagery']]);
    // 99 + 49.50 + 35 + 49 + 25 = 257.50
    expect($q->subtotalCents)->toBe(25750)
        ->and(collect($q->lines)->firstWhere('code', 'addon_rush')['amount_cents'])->toBe(4950)
        ->and($q->meta['turnaround_days'])->toBe(2)
        ->and($q->meta['revisions_included'])->toBe(2)
        ->and($q->meta['extra_revision_cents'])->toBe(3500);
});

it('applies a promo before GST and keeps totals consistent', function () {
    $q = designPricing()->design(['design_type' => 'brochure', 'package' => 'standard', 'promo' => ['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10]]);
    expect($q->subtotalCents)->toBe(35910)
        ->and($q->discountCents)->toBe(3990)
        ->and($q->gstCents)->toBe(3591)
        ->and($q->totalCents)->toBe($q->subtotalCents + $q->gstCents)
        ->and(array_sum(array_column($q->lines, 'amount_cents')))->toBe($q->subtotalCents);
});

it('falls back to the default design section when the rate card has none', function () {
    $card = RateCardDefaults::data();
    unset($card['design']);
    expect(designPricing($card)->design(['design_type' => 'business_card', 'package' => 'basic'])->subtotalCents)->toBe(7900);
});

it('uses edited rate card prices when present', function () {
    $card = RateCardDefaults::data();
    $card['design']['types']['flyer']['prices']['basic'] = 120;
    expect(designPricing($card)->design(['design_type' => 'flyer', 'package' => 'basic'])->subtotalCents)->toBe(12000);
});

it('rejects unknown design types, packages and add-ons', function (array $in) {
    designPricing()->design($in);
})->with([
    [['design_type' => 'logo', 'package' => 'basic']],
    [['design_type' => 'flyer', 'package' => 'gold']],
    [['design_type' => 'flyer', 'package' => 'basic', 'addons' => ['nope']]],
])->throws(DomainProblem::class);
