<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Domain\Pricing\RateCardDefaults;
use App\Exceptions\DomainProblem;
use App\Models\PrintOption;
use App\Models\PrintProduct;
use App\Models\PromoCode;
use App\Models\RateCard;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RateCardController extends AdminController
{
    public function show(Request $request): Response
    {
        $productId = $request->integer('product') ?: PrintProduct::orderBy('sort')->value('id');

        return Inertia::render('admin/rate-card/index', [
            'card' => ['id' => RateCard::where('is_active', true)->latest('id')->value('id'), 'data' => PricingFactory::rateCard()],
            'history' => RateCard::leftJoin('users', 'users.id', '=', 'rate_cards.created_by')->latest('rate_cards.id')->limit(30)->get(['rate_cards.id', 'rate_cards.name', 'rate_cards.is_active', 'rate_cards.created_at', 'users.name as creator_name']),
            'products' => PrintProduct::orderBy('sort')->get(['id', 'name', 'slug', 'gsm_options', 'quantity_options', 'finish_options', 'is_active']),
            'matrix' => $productId ? [
                'product_id' => $productId,
                'options' => PrintOption::where('print_product_id', $productId)->orderBy('gsm')->orderBy('sides')->orderBy('quantity')->get(['id', 'gsm', 'sides', 'quantity', 'unit_price_hc']),
            ] : null,
            'promos' => PromoCode::latest('id')->get(),
            'tab' => $request->string('tab')->toString() ?: 'distribution',
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'data.gst_rate' => ['required', 'numeric', 'min:0', 'max:0.5'],
            'data.distribution.coverage' => ['required', 'array', 'min:1'],
            'data.distribution.coverage.*.label' => ['required', 'string', 'max:60'],
            'data.distribution.coverage.*.per_item' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.distribution.materials' => ['required', 'array', 'min:1'],
            'data.distribution.materials.*.label' => ['required', 'string', 'max:60'],
            'data.distribution.materials.*.add' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.distribution.quantity_bands' => ['required', 'array', 'min:1'],
            'data.distribution.quantity_bands.*.min' => ['required', 'integer', 'min:0'],
            'data.distribution.quantity_bands.*.discount_pct' => ['required', 'numeric', 'min:0', 'max:90'],
            'data.distribution.minimum_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'data.distribution.distributor_pay_per_item' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.print.minimum_order' => ['required', 'numeric', 'min:0', 'max:100000'],
            'data.print.turnaround_days' => ['required', 'integer', 'min:1', 'max:60'],
            'data.print.finishes' => ['required', 'array', 'min:1'],
            'data.print.finishes.*.label' => ['required', 'string', 'max:60'],
            'data.print.finishes.*.pct' => ['required', 'numeric', 'min:-50', 'max:500'],
            'data.print.addons' => ['present', 'array'],
            'data.print.addons.*.label' => ['required', 'string', 'max:80'],
            'data.print.addons.*.pct' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'data.print.addons.*.fixed' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'data.design' => ['sometimes', 'array'],
            'data.design.double_sided_pct' => ['required_with:data.design', 'numeric', 'min:0', 'max:500'],
            'data.design.rush_turnaround_days' => ['required_with:data.design', 'integer', 'min:1', 'max:30'],
            'data.design.types' => ['required_with:data.design', 'array', 'min:1'],
            'data.design.types.*.label' => ['required', 'string', 'max:80'],
            'data.design.types.*.sides_option' => ['boolean'],
            'data.design.types.*.prices' => ['required', 'array'],
            'data.design.types.*.prices.*' => ['required', 'numeric', 'min:0', 'max:100000'],
            'data.design.packages' => ['required_with:data.design', 'array', 'min:1'],
            'data.design.packages.*.label' => ['required', 'string', 'max:60'],
            'data.design.packages.*.description' => ['nullable', 'string', 'max:255'],
            'data.design.packages.*.revisions' => ['required', 'integer', 'min:0', 'max:99'],
            'data.design.packages.*.turnaround_days' => ['required', 'integer', 'min:1', 'max:60'],
            'data.design.addons' => ['present_with:data.design', 'array'],
            'data.design.addons.*.label' => ['required', 'string', 'max:80'],
            'data.design.addons.*.pct' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'data.design.addons.*.fixed' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function normalise(array $data): array
    {
        $d = $data;
        $d['gst_rate'] = (float) $d['gst_rate'];
        foreach ($d['distribution']['coverage'] as $k => $c) {
            $d['distribution']['coverage'][$k] = ['label' => $c['label'], 'per_item' => (float) $c['per_item']];
        }
        foreach ($d['distribution']['materials'] as $k => $m) {
            $d['distribution']['materials'][$k] = ['label' => $m['label'], 'add' => (float) $m['add']];
        }
        $bands = array_map(fn ($b) => ['min' => (int) $b['min'], 'discount_pct' => (float) $b['discount_pct']], array_values($d['distribution']['quantity_bands']));
        usort($bands, fn ($a, $b) => $a['min'] <=> $b['min']);
        $d['distribution']['quantity_bands'] = $bands;
        $d['distribution']['minimum_fee'] = (float) $d['distribution']['minimum_fee'];
        $d['distribution']['distributor_pay_per_item'] = (float) $d['distribution']['distributor_pay_per_item'];
        $d['print']['minimum_order'] = (float) $d['print']['minimum_order'];
        $d['print']['turnaround_days'] = (int) $d['print']['turnaround_days'];
        foreach ($d['print']['finishes'] as $k => $f) {
            $d['print']['finishes'][$k] = ['label' => $f['label'], 'pct' => (float) $f['pct']];
        }
        foreach ($d['print']['addons'] ?? [] as $k => $a) {
            $d['print']['addons'][$k] = isset($a['pct']) && $a['pct'] !== null && $a['pct'] !== ''
                ? ['label' => $a['label'], 'pct' => (float) $a['pct']]
                : ['label' => $a['label'], 'fixed' => (float) ($a['fixed'] ?? 0)];
        }

        $design = $d['design'] ?? RateCardDefaults::design();
        $d['design'] = [
            'double_sided_pct' => (float) ($design['double_sided_pct'] ?? 0),
            'rush_turnaround_days' => (int) ($design['rush_turnaround_days'] ?? 2),
            'types' => [],
            'packages' => [],
            'addons' => [],
        ];
        foreach ($design['packages'] ?? [] as $k => $p) {
            $d['design']['packages'][$k] = ['label' => $p['label'], 'revisions' => (int) $p['revisions'], 'turnaround_days' => (int) $p['turnaround_days'], 'description' => (string) ($p['description'] ?? '')];
        }
        foreach ($design['types'] ?? [] as $k => $t) {
            $prices = [];
            foreach (array_keys($d['design']['packages']) as $pkg) {
                $prices[$pkg] = (float) ($t['prices'][$pkg] ?? 0);
            }
            $d['design']['types'][$k] = ['label' => $t['label'], 'sides_option' => filter_var($t['sides_option'] ?? false, FILTER_VALIDATE_BOOL), 'prices' => $prices];
        }
        foreach ($design['addons'] ?? [] as $k => $a) {
            $d['design']['addons'][$k] = isset($a['pct']) && $a['pct'] !== null && $a['pct'] !== ''
                ? ['label' => $a['label'], 'pct' => (float) $a['pct']]
                : ['label' => $a['label'], 'fixed' => (float) ($a['fixed'] ?? 0)];
        }

        return $d;
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate($this->rules());
        $data = $this->normalise($v['data']);
        $previous = PricingFactory::rateCard();
        DB::transaction(function () use ($data, $v, $request, $previous) {
            RateCard::where('is_active', true)->update(['is_active' => false]);
            $card = RateCard::create([
                'name' => $v['name'] ?? 'Rate card '.now(config('epfd.timezone'))->format('d M Y H:i'),
                'is_active' => true, 'data' => $data, 'created_by' => $request->user()->id,
            ]);
            Audit::log('rate_card.saved', $card, ['before' => $previous, 'after' => $data]);
        });

        return back()->with('success', 'New rate card version saved and activated.');
    }

    public function activate(RateCard $rateCard): RedirectResponse
    {
        DB::transaction(function () use ($rateCard) {
            RateCard::where('is_active', true)->update(['is_active' => false]);
            $rateCard->forceFill(['is_active' => true])->save();
        });
        Audit::log('rate_card.activated', $rateCard);

        return back()->with('success', "Rate card #{$rateCard->id} re-activated.");
    }

    /** Live preview with the unsaved draft card, through the SAME PricingService. */
    public function preview(Request $request): JsonResponse
    {
        $request->validate($this->rules() + [
            'input.service' => ['required', 'in:distribution,print,design'],
            'input.promo' => ['nullable', 'string', 'max:40'],
        ]);
        $card = $this->normalise($request->input('data'));
        $in = (array) $request->input('input');
        $pricing = new PricingService($card, PricingFactory::printMatrix());
        try {
            $promo = ! empty($in['promo']) ? PricingFactory::promo($in['promo'], $in['service']) : null;
            if ($in['service'] === 'design') {
                $quote = $pricing->design([
                    'design_type' => (string) ($in['design_type'] ?? 'flyer'), 'package' => (string) ($in['package'] ?? 'standard'),
                    'sides' => (int) ($in['sides'] ?? 1), 'addons' => array_values((array) ($in['addons'] ?? [])), 'promo' => $promo,
                ]);
            } elseif ($in['service'] === 'distribution') {
                $quote = $pricing->distribution([
                    'houses' => (int) ($in['houses'] ?? 0), 'units' => (int) ($in['units'] ?? 0),
                    'coverage' => $in['coverage'] ?? 'houses_units', 'material' => $in['material'] ?? 'standard_dl', 'promo' => $promo,
                ]);
            } else {
                $product = PrintProduct::find((int) ($in['product_id'] ?? 0));
                $quote = $pricing->print([
                    'product_id' => (int) ($in['product_id'] ?? 0), 'product_name' => $product?->name, 'gsm' => (int) ($in['gsm'] ?? 0),
                    'sides' => (int) ($in['sides'] ?? 1), 'quantity' => (int) ($in['quantity'] ?? 0), 'finish' => $in['finish'] ?? 'matt',
                    'addons' => array_values((array) ($in['addons'] ?? [])), 'promo' => $promo,
                ]);
            }
        } catch (DomainProblem $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->problemCode], 422);
        }

        return response()->json(['quote' => $quote->toArray()]);
    }

    public function matrix(Request $request, PrintProduct $product): RedirectResponse
    {
        $data = $request->validate([
            'cells' => ['required', 'array', 'min:1'],
            'cells.*.id' => ['required', 'integer'],
            'cells.*.unit_price_hc' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        $changed = 0;
        DB::transaction(function () use ($data, $product, &$changed) {
            foreach ($data['cells'] as $c) {
                $changed += PrintOption::where('print_product_id', $product->id)->whereKey($c['id'])->where('unit_price_hc', '!=', $c['unit_price_hc'])->update(['unit_price_hc' => $c['unit_price_hc']]);
            }
        });
        PricingFactory::forgetMatrix();
        Audit::log('print_matrix.saved', null, ['product_id' => $product->id, 'product' => $product->name, 'cells_changed' => $changed]);

        return back()->with('success', "Price matrix saved ({$changed} cell(s) changed).");
    }

    // ------------------------------------------------------------------ promo codes

    /** @return array<string, mixed> */
    private function promoRules(?PromoCode $promo = null): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/i', Rule::unique('promo_codes', 'code')->ignore($promo?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.01', $promo?->type === 'percent' || request('type') === 'percent' ? 'max:100' : 'max:100000'],
            'service' => ['required', 'in:any,distribution,print,design'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ];
    }

    /** @param array<string, mixed> $v */
    private function promoAttrs(array $v): array
    {
        return [
            'code' => strtoupper($v['code']), 'description' => $v['description'] ?? null, 'type' => $v['type'],
            'value' => $v['type'] === 'percent' ? (int) $v['value'] : (int) round($v['value'] * 100),
            'service' => $v['service'], 'min_subtotal_cents' => (int) round(($v['min_subtotal'] ?? 0) * 100),
            'starts_at' => $v['starts_at'] ?? null, 'ends_at' => $v['ends_at'] ?? null, 'max_uses' => $v['max_uses'] ?? null,
            'is_active' => (bool) ($v['is_active'] ?? true),
        ];
    }

    public function storePromo(Request $request): RedirectResponse
    {
        $promo = PromoCode::create($this->promoAttrs($request->validate($this->promoRules())));
        Audit::log('promo.created', $promo, $promo->toArray());

        return back()->with('success', "Promo {$promo->code} created.");
    }

    public function updatePromo(Request $request, PromoCode $promo): RedirectResponse
    {
        $before = $promo->toArray();
        $promo->update($this->promoAttrs($request->validate($this->promoRules($promo))));
        Audit::log('promo.updated', $promo, ['before' => $before, 'after' => $promo->fresh()?->toArray()]);

        return back()->with('success', "Promo {$promo->code} updated.");
    }

    public function destroyPromo(PromoCode $promo): RedirectResponse
    {
        Audit::log('promo.deleted', $promo, $promo->toArray());
        $promo->delete();

        return back()->with('success', 'Promo deleted.');
    }
}
