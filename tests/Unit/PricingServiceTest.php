<?php

declare(strict_types=1);

use App\Domain\Pricing\PricingService;
use App\Domain\Pricing\RateCardDefaults;
use App\Exceptions\DomainProblem;

function pricing(array $matrix = ['1|150|1|1000' => 400, '1|150|2|1000' => 480]): PricingService
{
    return new PricingService(RateCardDefaults::data(), $matrix);
}

it('prices houses + units at $0.81 ex GST (≈89c incl. GST) per letterbox', function () {
    $q = pricing()->distribution(['houses' => 35, 'units' => 13]);
    expect($q->subtotalCents)->toBe(3888)            // 48 × 81c
        ->and($q->gstCents)->toBe(389)               // 10%, rounded
        ->and($q->totalCents)->toBe(4277)
        ->and(round($q->perItemInclGstCents, 1))->toBe(89.1);
});

it('bills only the selected coverage', function () {
    expect(pricing()->distribution(['houses' => 100, 'units' => 50, 'coverage' => 'houses_only'])->meta['letterboxes'])->toBe(100)
        ->and(pricing()->distribution(['houses' => 100, 'units' => 50, 'coverage' => 'units_only'])->meta['letterboxes'])->toBe(50);
});

it('adds material modifiers per item', function (string $material, int $addCentsPer1000) {
    $base = pricing()->distribution(['houses' => 1000, 'units' => 0, 'coverage' => 'houses_units'])->subtotalCents;
    $with = pricing()->distribution(['houses' => 1000, 'units' => 0, 'coverage' => 'houses_units', 'material' => $material])->subtotalCents;
    expect($with - $base)->toBe($addCentsPer1000);
})->with([
    ['standard_dl', 0], ['a5', 0], ['folded', 1000], ['envelope', 2000], ['magnet', 4000], ['booklet', 6000], ['double_up', 8000], ['magazine', 10000],
]);

it('applies the quantity band discount', function () {
    $q = pricing()->distribution(['houses' => 6000, 'units' => 0]);
    $discount = collect($q->lines)->firstWhere('code', 'volume_discount');
    expect($discount['amount_cents'])->toBe(-14580); // 3% of 6000 × 81c
});

it('tops up to the minimum distribution fee', function () {
    $q = pricing()->distribution(['houses' => 5, 'units' => 0]);
    expect($q->subtotalCents)->toBe(2000)->and(collect($q->lines)->pluck('code'))->toContain('minimum');
});

it('applies percent and fixed promo codes before GST', function () {
    $pct = pricing()->distribution(['houses' => 1000, 'units' => 0, 'promo' => ['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10]]);
    $fixed = pricing()->distribution(['houses' => 1000, 'units' => 0, 'promo' => ['code' => 'X', 'type' => 'fixed', 'value' => 500]]);
    expect($pct->subtotalCents)->toBe(72900)->and($pct->gstCents)->toBe(7290)
        ->and($fixed->subtotalCents)->toBe(80500);
});

it('itemises GST and keeps totals consistent', function () {
    $q = pricing()->distribution(['houses' => 1234, 'units' => 567, 'material' => 'folded']);
    expect($q->totalCents)->toBe($q->subtotalCents + $q->gstCents)
        ->and(array_sum(array_column($q->lines, 'amount_cents')))->toBe($q->subtotalCents);
});

it('prices print from the matrix with finish and add-ons', function () {
    $q = pricing()->print(['product_id' => 1, 'gsm' => 150, 'sides' => 2, 'quantity' => 1000, 'finish' => 'gloss', 'addons' => ['hard_proof']]);
    // 480 hc × 1.05 (gloss) = 504 hc/pc → $50.40 + $15 proof = $65.40
    expect($q->subtotalCents)->toBe(6540)->and($q->gstCents)->toBe(654)->and($q->totalCents)->toBe(7194);
});

it('applies the print minimum order', function () {
    $q = pricing(['1|150|1|100' => 400])->print(['product_id' => 1, 'gsm' => 150, 'sides' => 1, 'quantity' => 100]);
    expect($q->subtotalCents)->toBe(2500);
});

it('rejects unknown combinations', function () {
    pricing()->print(['product_id' => 1, 'gsm' => 999, 'sides' => 1, 'quantity' => 1000]);
})->throws(DomainProblem::class);

it('rejects unknown coverage', function () {
    pricing()->distribution(['houses' => 1, 'units' => 1, 'coverage' => 'nope']);
})->throws(DomainProblem::class);
