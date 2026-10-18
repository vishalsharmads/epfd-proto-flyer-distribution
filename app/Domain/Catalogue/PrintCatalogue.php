<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Pricing\PricingFactory;
use App\Exceptions\DomainProblem;
use App\Models\PrintOption;
use App\Models\PrintProduct;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a product's price matrix in step with the options it offers: one cell per paper weight × sides ×
 * quantity. New combinations start unpriced (NULL) and are never sold; a product cannot be active while
 * any cell is unpriced.
 */
final class PrintCatalogue
{
    /** @return array{added:int, removed:int, unpriced:int} */
    public function syncMatrix(PrintProduct $product): array
    {
        $wanted = [];
        foreach (array_map('intval', $product->gsm_options) as $gsm) {
            foreach ($product->sides() as $sides) {
                foreach (array_map('intval', $product->quantity_options) as $qty) {
                    $wanted["{$gsm}|{$sides}|{$qty}"] = ['gsm' => $gsm, 'sides' => $sides, 'quantity' => $qty];
                }
            }
        }

        [$added, $removed] = DB::transaction(function () use ($product, $wanted) {
            $existing = $product->options()->get(['id', 'gsm', 'sides', 'quantity'])
                ->keyBy(fn (PrintOption $o) => "{$o->gsm}|{$o->sides}|{$o->quantity}");
            $stale = $existing->diffKeys($wanted)->pluck('id');
            if ($stale->isNotEmpty()) {
                PrintOption::whereIn('id', $stale)->delete();
            }
            $missing = array_diff_key($wanted, $existing->all());
            if ($missing) {
                PrintOption::insert(array_map(fn (array $c) => $c + ['print_product_id' => $product->id, 'unit_price_hc' => null], array_values($missing)));
            }

            return [count($missing), $stale->count()];
        });
        PricingFactory::forgetMatrix();

        return ['added' => $added, 'removed' => $removed, 'unpriced' => $this->unpricedCount($product)];
    }

    /** "From" price shown to customers: the cheapest priced per-piece cell (before finish surcharge), in whole cents. */
    public function refreshFromPrice(PrintProduct $product): void
    {
        $minHc = $product->options()->whereNotNull('unit_price_hc')->min('unit_price_hc');
        $product->forceFill(['from_price_cents' => $minHc === null ? 0 : max(1, (int) ceil($minHc / 100))])->saveQuietly();
    }

    public function unpricedCount(PrintProduct $product): int
    {
        return $product->options()->whereNull('unit_price_hc')->count();
    }

    /** Throws when the product would go on sale with combinations that have no price. */
    public function assertSellable(PrintProduct $product): void
    {
        $unpriced = $this->unpricedCount($product);
        if ($unpriced > 0) {
            throw new DomainProblem('catalogue_unpriced', "{$product->name} has {$unpriced} unpriced option(s). Set their prices in Rate card → Print matrix before activating it.");
        }
    }
}
