<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Exceptions\DomainProblem;
use App\Models\Artwork;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Support\BusinessDays;
use Illuminate\Support\Facades\Storage;

/** Re-prices a print order draft with the shared PricingService (same maths as /api/v1/pricing/print/quote). */
final class PrintPricer
{
    /** @param list<string> $addons */
    public static function reprice(PrintOrder $order, array $addons = []): PrintOrder
    {
        $order->loadMissing('product');
        $p = $order->product;
        if (! $p || ! $order->gsm || ! $order->quantity) {
            $order->forceFill(['subtotal_cents' => 0, 'gst_cents' => 0, 'total_cents' => 0, 'pricing' => ['meta' => ['addons' => $addons]], 'earliest_delivery' => null])->save();

            return $order;
        }
        $promo = null;
        try {
            $promo = PricingFactory::promo($order->promo_code, 'print');
        } catch (DomainProblem) {
            $promo = null;
        }
        $quote = app(PricingService::class)->print([
            'product_id' => $p->id,
            'product_name' => $p->name,
            'gsm' => (int) $order->gsm,
            'sides' => (int) $order->sides,
            'quantity' => (int) $order->quantity,
            'finish' => $order->finish ?? 'matt',
            'addons' => $addons,
            'promo' => $promo,
        ])->toArray();
        $days = max((int) $p->turnaround_days, (int) $quote['meta']['turnaround_days']) + (in_array('express', $addons, true) ? -2 : 0) + 2;
        $earliest = BusinessDays::add($days);
        $quote['meta']['addons'] = array_values($addons);
        $quote['meta']['earliest_delivery'] = $earliest->toDateString();

        $order->forceFill([
            'subtotal_cents' => $quote['subtotal_cents'],
            'gst_cents' => $quote['gst_cents'],
            'total_cents' => $quote['total_cents'],
            'pricing' => $quote,
            'earliest_delivery' => $earliest->toDateString(),
        ])->save();

        return $order;
    }

    /** Switch a draft to a product with sensible defaults (mid paper weight, 1,000 pcs), dropping old artwork, and re-price. */
    public static function applyProduct(PrintOrder $order, PrintProduct $p): void
    {
        $gsm = (int) ($p->gsm_options[min(1, count($p->gsm_options) - 1)] ?? $p->gsm_options[0]);
        $qtys = array_map('intval', $p->quantity_options);
        $order->forceFill([
            'print_product_id' => $p->id,
            'gsm' => $gsm,
            'finish' => ((array) ($p->finish_options[(string) $gsm] ?? ['matt']))[0],
            'sides' => 1,
            'orientation' => $p->width_mm > $p->height_mm ? 'landscape' : 'portrait',
            'quantity' => in_array(1000, $qtys, true) ? 1000 : $qtys[intdiv(count($qtys), 2)],
            'also_distribute' => false,
        ])->save();
        $order->artworks()->get()->each(fn (Artwork $a) => self::removeArtwork($a));
        self::reprice($order->fresh(), []);
    }

    public static function removeArtwork(Artwork $a): void
    {
        Storage::disk('local')->delete($a->path);
        $a->delete();
    }

    /** @return list<string> */
    public static function addons(PrintOrder $order): array
    {
        return array_values((array) ($order->pricing['meta']['addons'] ?? []));
    }

    /** @return list<array{value:string,label:string,pct:float|null,fixed_cents:int|null}> */
    public static function addonOptions(): array
    {
        $out = [];
        foreach ((array) (PricingFactory::rateCard()['print']['addons'] ?? []) as $k => $a) {
            $out[] = ['value' => (string) $k, 'label' => (string) $a['label'], 'pct' => isset($a['pct']) ? (float) $a['pct'] : null, 'fixed_cents' => isset($a['fixed']) ? (int) round((float) $a['fixed'] * 100) : null];
        }

        return $out;
    }
}
