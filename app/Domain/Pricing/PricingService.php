<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Exceptions\DomainProblem;

/**
 * The ONE pricing engine, used by the business UI, the REST API and the admin preview calculator.
 *
 * Pure: it receives the rate card (array, see RateCardDefaults) and the print price matrix as data and
 * does no I/O, so it is trivially unit-testable. Money is computed in hundredths of a cent ("hc")
 * internally to avoid float drift, then rounded half-up to cents per line.
 *
 * Distribution: letterboxes × (coverage rate + material add-on) − quantity-band discount − promo,
 *               topped up to the minimum fee, + GST.
 * Print:        quantity × matrix unit price (product/gsm/sides/qty) × finish factor + add-ons − promo,
 *               topped up to the minimum order, + GST.
 * Design:       package price for the design type (× double-sided uplift) + add-ons (rush % of the design
 *               line, fixed extras) − promo, + GST. A card without a `design` section falls back to
 *               RateCardDefaults::design() so databases seeded before Design Studio keep working.
 */
final class PricingService
{
    /**
     * @param  array<string, mixed>  $rateCard
     * @param  array<string, int>  $printMatrix  key "productId|gsm|sides|qty" => unit price in hc (ex GST)
     */
    public function __construct(private readonly array $rateCard, private readonly array $printMatrix = []) {}

    /** @return array<string, mixed> */
    public function rateCard(): array
    {
        return $this->rateCard;
    }

    /**
     * @param  array{houses:int, units:int, coverage?:string, material?:string, promo?: array{code:string,type:string,value:int}|null}  $in
     */
    public function distribution(array $in): Quote
    {
        $card = $this->rateCard['distribution'];
        $coverage = $in['coverage'] ?? 'houses_units';
        $material = $in['material'] ?? 'standard_dl';
        if (! isset($card['coverage'][$coverage])) {
            throw new DomainProblem('pricing_unknown_coverage', "Unknown coverage '{$coverage}'.");
        }
        if (! isset($card['materials'][$material])) {
            throw new DomainProblem('pricing_unknown_material', "Unknown material '{$material}'.");
        }

        $houses = max(0, (int) $in['houses']);
        $units = max(0, (int) $in['units']);
        $qty = match ($coverage) {
            'houses_only' => $houses,
            'units_only' => $units,
            default => $houses + $units,
        };

        $rateHc = self::hc((float) $card['coverage'][$coverage]['per_item']);
        $addHc = self::hc((float) $card['materials'][$material]['add']);
        $lines = [];
        $lines[] = self::line('distribution', 'Letterbox distribution — '.$card['coverage'][$coverage]['label'], $qty, $rateHc);
        if ($addHc > 0) {
            $lines[] = self::line('material', 'Material: '.$card['materials'][$material]['label'], $qty, $addHc);
        }

        $gross = array_sum(array_column($lines, 'amount_cents'));
        $band = $this->band($card['quantity_bands'] ?? [], $qty);
        $discount = 0;
        if ($band['discount_pct'] > 0 && $gross > 0) {
            $discount = (int) round($gross * $band['discount_pct'] / 100);
            $lines[] = ['code' => 'volume_discount', 'label' => "Volume discount ({$band['discount_pct']}% for {$band['min']}+)", 'quantity' => 1, 'unit_cents' => -$discount, 'amount_cents' => -$discount];
        }

        [$lines, $promoCents] = $this->applyPromo($lines, $in['promo'] ?? null, 'distribution');
        $discount += $promoCents;

        $lines = $this->applyMinimum($lines, $qty > 0 ? self::cents((float) ($card['minimum_fee'] ?? 0)) : 0, 'Minimum distribution fee adjustment');

        return $this->finish('distribution', $lines, $discount, $qty, [
            'letterboxes' => $qty, 'houses' => $houses, 'units' => $units, 'coverage' => $coverage, 'material' => $material,
            'rate_per_item_ex_gst' => ($rateHc + $addHc) / 10000,
        ]);
    }

    /**
     * @param  array{product_id:int, product_name?:string, gsm:int, sides:int, quantity:int, finish?:string, addons?:list<string>, promo?: array{code:string,type:string,value:int}|null}  $in
     */
    public function print(array $in): Quote
    {
        $card = $this->rateCard['print'];
        $key = "{$in['product_id']}|{$in['gsm']}|{$in['sides']}|{$in['quantity']}";
        if (! isset($this->printMatrix[$key])) {
            throw new DomainProblem('pricing_unavailable_combination', 'That paper weight, sides and quantity combination is not available for this product.');
        }
        $unitHc = $this->printMatrix[$key];
        $finish = $in['finish'] ?? 'matt';
        $finishPct = (float) ($card['finishes'][$finish]['pct'] ?? 0);
        $unitHc = (int) round($unitHc * (1 + $finishPct / 100));

        $qty = (int) $in['quantity'];
        $name = $in['product_name'] ?? 'Print';
        $lines = [self::line('print', sprintf('%s — %dgsm %s, %s-sided', $name, $in['gsm'], ucfirst($finish), $in['sides'] === 2 ? 'double' : 'single'), $qty, $unitHc)];

        foreach ($in['addons'] ?? [] as $code) {
            $addon = $card['addons'][$code] ?? null;
            if (! $addon) {
                throw new DomainProblem('pricing_unknown_addon', "Unknown add-on '{$code}'.");
            }
            $amount = isset($addon['pct'])
                ? (int) round($lines[0]['amount_cents'] * $addon['pct'] / 100)
                : self::cents((float) $addon['fixed']);
            $lines[] = ['code' => 'addon_'.$code, 'label' => $addon['label'], 'quantity' => 1, 'unit_cents' => $amount, 'amount_cents' => $amount];
        }

        [$lines, $promoCents] = $this->applyPromo($lines, $in['promo'] ?? null, 'print');
        $lines = $this->applyMinimum($lines, self::cents((float) ($card['minimum_order'] ?? 0)), 'Minimum print order adjustment');

        return $this->finish('print', $lines, $promoCents, $qty, [
            'quantity' => $qty, 'gsm' => $in['gsm'], 'sides' => $in['sides'], 'finish' => $finish,
            'turnaround_days' => (int) ($card['turnaround_days'] ?? 3),
        ]);
    }

    /**
     * @param  array{design_type:string, package:string, sides?:int, addons?:list<string>, promo?: array{code:string,type:string,value:int}|null}  $in
     */
    public function design(array $in): Quote
    {
        $card = $this->designCard();
        $type = $in['design_type'];
        $package = $in['package'];
        if (! isset($card['types'][$type])) {
            throw new DomainProblem('pricing_unknown_design_type', "Unknown design type '{$type}'.");
        }
        if (! isset($card['packages'][$package], $card['types'][$type]['prices'][$package])) {
            throw new DomainProblem('pricing_unknown_package', "Unknown design package '{$package}'.");
        }
        $t = $card['types'][$type];
        $pkg = $card['packages'][$package];
        $sides = ! empty($t['sides_option']) && (int) ($in['sides'] ?? 1) === 2 ? 2 : 1;

        $hc = self::hc((float) $t['prices'][$package]);
        if ($sides === 2) {
            $hc = (int) round($hc * (1 + (float) ($card['double_sided_pct'] ?? 0) / 100));
        }
        $label = sprintf('%s — %s package%s', $t['label'], $pkg['label'], ! empty($t['sides_option']) ? ($sides === 2 ? ', double-sided' : ', single-sided') : '');
        $lines = [self::line('design', $label, 1, $hc)];

        $revisions = (int) $pkg['revisions'];
        $turnaround = (int) $pkg['turnaround_days'];
        $addons = array_values(array_unique($in['addons'] ?? []));
        foreach ($addons as $code) {
            $addon = $card['addons'][$code] ?? null;
            if (! $addon) {
                throw new DomainProblem('pricing_unknown_addon', "Unknown add-on '{$code}'.");
            }
            $amount = isset($addon['pct'])
                ? (int) round($lines[0]['amount_cents'] * (float) $addon['pct'] / 100)
                : self::cents((float) $addon['fixed']);
            $lines[] = ['code' => 'addon_'.$code, 'label' => $addon['label'], 'quantity' => 1, 'unit_cents' => $amount, 'amount_cents' => $amount];
            if ($code === 'extra_revision' && $revisions < 99) {
                $revisions++;
            }
            if ($code === 'rush') {
                $turnaround = min($turnaround, (int) ($card['rush_turnaround_days'] ?? 2));
            }
        }

        [$lines, $promoCents] = $this->applyPromo($lines, $in['promo'] ?? null, 'design');

        return $this->finish('design', $lines, $promoCents, 1, [
            'design_type' => $type, 'package' => $package, 'sides' => $sides, 'addons' => $addons,
            'revisions_included' => $revisions, 'revisions_unlimited' => $revisions >= 99, 'turnaround_days' => $turnaround,
            'extra_revision_cents' => self::cents((float) ($card['addons']['extra_revision']['fixed'] ?? 0)),
        ]);
    }

    /** The design section of the rate card, falling back to the built-in defaults. @return array<string, mixed> */
    public function designCard(): array
    {
        return $this->rateCard['design'] ?? RateCardDefaults::design();
    }

    // ------------------------------------------------------------------ internals

    /** @return array{min:int, discount_pct:float} */
    private function band(array $bands, int $qty): array
    {
        $best = ['min' => 0, 'discount_pct' => 0.0];
        foreach ($bands as $b) {
            if ($qty >= (int) $b['min'] && (int) $b['min'] >= $best['min']) {
                $best = ['min' => (int) $b['min'], 'discount_pct' => (float) $b['discount_pct']];
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function applyPromo(array $lines, ?array $promo, string $service): array
    {
        if (! $promo) {
            return [$lines, 0];
        }
        $base = array_sum(array_column($lines, 'amount_cents'));
        $off = $promo['type'] === 'percent'
            ? (int) round($base * min(100, (int) $promo['value']) / 100)
            : min($base, (int) $promo['value']);
        if ($off <= 0) {
            return [$lines, 0];
        }
        $lines[] = ['code' => 'promo', 'label' => 'Promo '.strtoupper($promo['code']), 'quantity' => 1, 'unit_cents' => -$off, 'amount_cents' => -$off];

        return [$lines, $off];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function applyMinimum(array $lines, int $minimumCents, string $label): array
    {
        $sum = array_sum(array_column($lines, 'amount_cents'));
        if ($minimumCents > 0 && $sum < $minimumCents) {
            $diff = $minimumCents - $sum;
            $lines[] = ['code' => 'minimum', 'label' => $label, 'quantity' => 1, 'unit_cents' => $diff, 'amount_cents' => $diff];
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines */
    private function finish(string $service, array $lines, int $discount, int $qty, array $meta): Quote
    {
        $subtotal = max(0, (int) array_sum(array_column($lines, 'amount_cents')));
        $gst = (int) round($subtotal * (float) ($this->rateCard['gst_rate'] ?? 0.10));
        $total = $subtotal + $gst;

        return new Quote($service, array_values($lines), $subtotal, $discount, $gst, $total, $qty > 0 ? $total / $qty : 0.0, $meta);
    }

    /** @return array{code:string, label:string, quantity:int, unit_cents:float, amount_cents:int} */
    private static function line(string $code, string $label, int $qty, int $unitHc): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'quantity' => $qty,
            'unit_cents' => $unitHc / 100,
            'amount_cents' => (int) round($qty * $unitHc / 100, 0, PHP_ROUND_HALF_UP),
        ];
    }

    private static function hc(float $dollars): int
    {
        return (int) round($dollars * 10000);
    }

    private static function cents(float $dollars): int
    {
        return (int) round($dollars * 100);
    }
}
