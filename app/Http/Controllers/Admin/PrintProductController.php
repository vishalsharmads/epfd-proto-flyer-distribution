<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalogue\PrintCatalogue;
use App\Domain\Pricing\PricingFactory;
use App\Models\PrintCategory;
use App\Models\PrintProduct;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Print products: the print catalogue customers order from. Prices live in Rate card → Print matrix. */
class PrintProductController extends AdminController
{
    public function __construct(private readonly PrintCatalogue $catalogue) {}

    public function index(): Response
    {
        $unpriced = DB::table('print_options')->whereNull('unit_price_hc')->groupBy('print_product_id')->selectRaw('print_product_id, count(*) AS n')->pluck('n', 'print_product_id');
        $orders = DB::table('print_orders')->groupBy('print_product_id')->selectRaw('print_product_id, count(*) AS n')->pluck('n', 'print_product_id');

        return Inertia::render('admin/catalogue/print/index', [
            'products' => PrintProduct::with('printCategory:slug,name')->orderBy('sort')->orderBy('name')->get()->map(fn (PrintProduct $p) => [
                'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'category' => $p->category, 'category_name' => $p->printCategory?->name,
                'size_label' => $p->size_label, 'width_mm' => $p->width_mm, 'height_mm' => $p->height_mm, 'from_price_cents' => $p->from_price_cents,
                'image_url' => $p->imageUrl(), 'is_active' => $p->is_active, 'distributable' => $p->distributable, 'sort' => $p->sort,
                'unpriced' => (int) ($unpriced[$p->id] ?? 0), 'orders' => (int) ($orders[$p->id] ?? 0),
            ]),
            'categories' => PrintCategory::withCount('products')->orderBy('sort')->orderBy('name')->get(),
            'icons' => PrintCategory::ICONS,
        ]);
    }

    public function create(): Response
    {
        return $this->form(new PrintProduct([
            'category' => PrintCategory::orderBy('sort')->value('slug'), 'bleed_mm' => 3, 'turnaround_days' => 3,
            'gsm_options' => [150], 'sides_options' => [1, 2], 'finish_options' => ['150' => ['matt']], 'quantity_options' => [250, 500, 1000],
            'distributable' => false, 'is_active' => false, 'sort' => (int) PrintProduct::max('sort') + 1,
        ]));
    }

    public function edit(PrintProduct $product): Response
    {
        return $this->form($product);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = new PrintProduct;
        $message = $this->save($request, $product);

        return to_route('admin.catalogue.print.edit', $product)->with(...$message);
    }

    public function update(Request $request, PrintProduct $product): RedirectResponse
    {
        return back()->with(...$this->save($request, $product));
    }

    /** Activate / deactivate. A product with unpriced options cannot go on sale. */
    public function toggle(PrintProduct $product): RedirectResponse
    {
        return $this->attempt(function () use ($product) {
            if (! $product->is_active) {
                $this->catalogue->assertSellable($product);
            }
            $product->update(['is_active' => ! $product->is_active]);
            Audit::log('print_product.'.($product->is_active ? 'activated' : 'deactivated'), $product, ['name' => $product->name]);
        }, $product->is_active ? "{$product->name} is hidden from customers." : "{$product->name} is now on sale.");
    }

    /** Delete a product nobody has ordered; ordered products are deactivated instead (orders keep a reference). */
    public function destroy(PrintProduct $product): RedirectResponse
    {
        if ($product->is_active || DB::table('print_orders')->where('print_product_id', $product->id)->exists()
            || DB::table('design_orders')->where('print_product_id', $product->id)->exists()) {
            return back()->with('error', 'Only inactive products that have never been ordered can be deleted. Deactivate it instead.');
        }
        Audit::log('print_product.deleted', $product, ['name' => $product->name, 'slug' => $product->slug]);
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }
        $product->delete();
        PricingFactory::forgetMatrix();

        return to_route('admin.catalogue.print.index')->with('success', "{$product->name} deleted.");
    }

    private function form(PrintProduct $product): Response
    {
        $card = PricingFactory::rateCard();

        return Inertia::render('admin/catalogue/print/edit', [
            'product' => $product->exists ? $product->only('id', 'slug', 'name', 'description', 'category', 'size_label', 'width_mm', 'height_mm', 'bleed_mm', 'from_price_cents', 'gsm_options', 'finish_options', 'quantity_options', 'distributable', 'distribution_material', 'turnaround_days', 'sort', 'is_active')
                + ['sides_options' => $product->sides(), 'image_url' => $product->imageUrl(), 'unpriced' => $this->catalogue->unpricedCount($product)]
                : $product->only('category', 'bleed_mm', 'turnaround_days', 'gsm_options', 'sides_options', 'finish_options', 'quantity_options', 'distributable', 'is_active', 'sort') + ['id' => null, 'image_url' => null, 'unpriced' => 0],
            'categories' => PrintCategory::orderBy('sort')->orderBy('name')->get(['slug', 'name', 'is_active']),
            'finishes' => collect($card['print']['finishes'] ?? [])->map(fn ($f, $k) => ['value' => (string) $k, 'label' => (string) $f['label']])->values(),
            'materials' => collect($card['distribution']['materials'] ?? [])->map(fn ($m, $k) => ['value' => (string) $k, 'label' => (string) ($m['label'] ?? $k)])->values(),
        ]);
    }

    /** @return array{0:string,1:string} flash [key, message] */
    private function save(Request $request, PrintProduct $product): array
    {
        $card = PricingFactory::rateCard();
        $finishKeys = array_map('strval', array_keys((array) ($card['print']['finishes'] ?? [])));
        $materials = array_map('strval', array_keys((array) ($card['distribution']['materials'] ?? [])));
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('print_products', 'slug')->ignore($product->id)],
            'category' => ['required', Rule::exists('print_categories', 'slug')],
            'description' => ['nullable', 'string', 'max:1000'],
            'size_label' => ['required', 'string', 'max:60'],
            'width_mm' => ['required', 'integer', 'min:10', 'max:10000'],
            'height_mm' => ['required', 'integer', 'min:10', 'max:10000'],
            'bleed_mm' => ['required', 'integer', 'min:0', 'max:50'],
            'gsm_options' => ['required', 'array', 'min:1', 'max:12'],
            'gsm_options.*' => ['integer', 'distinct', 'min:50', 'max:2000'],
            'sides_options' => ['required', 'array', 'min:1'],
            'sides_options.*' => ['integer', 'distinct', 'in:1,2'],
            'quantity_options' => ['required', 'array', 'min:1', 'max:30'],
            'quantity_options.*' => ['integer', 'distinct', 'min:1', 'max:1000000'],
            'finish_options' => ['nullable', 'array'],
            'finish_options.*' => ['array', 'min:1'],
            'finish_options.*.*' => ['string', Rule::in($finishKeys)],
            'distributable' => ['boolean'],
            'distribution_material' => ['nullable', 'required_if_accepted:distributable', Rule::in($materials)],
            'turnaround_days' => ['required', 'integer', 'min:1', 'max:60'],
            'sort' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['boolean'],
        ], [
            'distribution_material.required_if_accepted' => 'Choose the distribution material used when this product is letterboxed.',
            'finish_options.*.min' => 'Choose at least one finish for each paper weight.',
        ], ['gsm_options.*' => 'paper weight', 'quantity_options.*' => 'quantity', 'width_mm' => 'width', 'height_mm' => 'height', 'bleed_mm' => 'bleed']);
        // Every paper weight needs at least one finish (form data drops empty lists, so check against the weights).
        $validator->after(function ($validator) use ($request, $finishKeys) {
            $missing = collect((array) $request->input('gsm_options', []))->map(fn ($g) => (int) $g)->filter()
                ->reject(fn (int $g) => array_intersect($finishKeys, (array) $request->input("finish_options.{$g}", [])) !== []);
            if ($missing->isNotEmpty()) {
                $validator->errors()->add('finish_options', 'Choose at least one finish for '.$missing->map(fn ($g) => "{$g}gsm")->implode(', ').'.');
            }
        });
        $v = $validator->validate();

        $gsms = array_values(array_unique(array_map('intval', $v['gsm_options'])));
        sort($gsms);
        $finishes = [];
        foreach ($gsms as $gsm) {
            $finishes[(string) $gsm] = array_values(array_intersect($finishKeys, (array) ($v['finish_options'][(string) $gsm] ?? [])));
        }
        $quantities = array_values(array_unique(array_map('intval', $v['quantity_options'])));
        sort($quantities);
        $sides = array_values(array_unique(array_map('intval', $v['sides_options'])));
        sort($sides);

        $wantsActive = (bool) ($v['is_active'] ?? false);
        $before = $product->exists ? $product->only(array_keys($v)) : [];
        DB::transaction(function () use ($product, $v, $gsms, $finishes, $quantities, $sides, $request) {
            $product->fill([
                'name' => $v['name'], 'slug' => $v['slug'], 'category' => $v['category'], 'description' => $v['description'] ?? null,
                'size_label' => $v['size_label'], 'width_mm' => $v['width_mm'], 'height_mm' => $v['height_mm'], 'bleed_mm' => $v['bleed_mm'],
                'gsm_options' => $gsms, 'sides_options' => $sides, 'finish_options' => $finishes, 'quantity_options' => $quantities,
                'distributable' => (bool) ($v['distributable'] ?? false),
                'distribution_material' => ($v['distributable'] ?? false) ? $v['distribution_material'] : null,
                'turnaround_days' => $v['turnaround_days'], 'sort' => $v['sort'],
            ]);
            $product->from_price_cents ??= 0;
            $product->is_active ??= false;
            if ($request->boolean('remove_image') && $product->image_path) {
                Storage::disk('public')->delete($product->image_path);
                $product->image_path = null;
            }
            if ($request->hasFile('image')) {
                if ($product->image_path) {
                    Storage::disk('public')->delete($product->image_path);
                }
                $product->image_path = $request->file('image')->store('catalogue/print', 'public');
            }
            $product->save();
        });

        $sync = $this->catalogue->syncMatrix($product);
        $this->catalogue->refreshFromPrice($product);
        $activated = false;
        if ($wantsActive && $sync['unpriced'] === 0) {
            $activated = ! $product->is_active;
            $product->update(['is_active' => true]);
        } elseif ($sync['unpriced'] > 0 && $product->is_active) {
            $product->update(['is_active' => false]);
        }

        Audit::log($before ? 'print_product.updated' : 'print_product.created', $product, [
            'before' => $before ?: null, 'after' => $product->only(array_keys($v)), 'matrix' => $sync,
        ]);

        if ($sync['unpriced'] > 0) {
            return ['warning', "Saved. {$sync['unpriced']} option(s) need a price in Rate card → Print matrix before {$product->name} can go on sale."];
        }

        return ['success', $activated ? "{$product->name} saved and on sale." : "{$product->name} saved."];
    }
}
