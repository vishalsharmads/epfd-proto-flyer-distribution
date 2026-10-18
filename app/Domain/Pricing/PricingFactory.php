<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Exceptions\DomainProblem;
use App\Models\PrintOption;
use App\Models\PromoCode;
use App\Models\RateCard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Loads the active rate card + print matrix from the database into a pure PricingService. */
final class PricingFactory
{
    public static function make(): PricingService
    {
        return new PricingService(self::rateCard(), self::printMatrix());
    }

    /** @return array<string, mixed> */
    public static function rateCard(): array
    {
        if (! Schema::hasTable('rate_cards')) {
            return RateCardDefaults::data();
        }

        $card = RateCard::where('is_active', true)->latest('id')->value('data') ?? RateCardDefaults::data();
        // Cards saved before Design Studio existed have no `design` section: use the defaults.
        $card['design'] ??= RateCardDefaults::design();

        return $card;
    }

    /** @return array<string, int> */
    public static function printMatrix(): array
    {
        return Cache::remember('pricing:print-matrix:'.self::matrixVersion(), 600, function () {
            $out = [];
            // Unpriced cells (NULL, new options an admin has not priced yet) are not for sale.
            foreach (PrintOption::query()->whereNotNull('unit_price_hc')->get(['print_product_id', 'gsm', 'sides', 'quantity', 'unit_price_hc']) as $o) {
                $out["{$o->print_product_id}|{$o->gsm}|{$o->sides}|{$o->quantity}"] = (int) $o->unit_price_hc;
            }

            return $out;
        });
    }

    public static function forgetMatrix(): void
    {
        Cache::forever('pricing:print-matrix-version', (string) microtime(true));
    }

    private static function matrixVersion(): string
    {
        return (string) Cache::get('pricing:print-matrix-version', '1');
    }

    /**
     * Resolve a promo code into the pure array the PricingService expects.
     *
     * @return array{code:string,type:string,value:int}|null
     */
    public static function promo(?string $code, string $service, int $subtotalCents = PHP_INT_MAX): ?array
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $promo = PromoCode::whereRaw('upper(code) = ?', [strtoupper(trim($code))])->first();
        if (! $promo || ! $promo->isUsableFor($service, $subtotalCents === PHP_INT_MAX ? $promo->min_subtotal_cents : $subtotalCents)) {
            throw new DomainProblem('promo_invalid', 'That promo code is not valid for this order.');
        }

        return ['code' => $promo->code, 'type' => $promo->type, 'value' => (int) $promo->value];
    }
}
