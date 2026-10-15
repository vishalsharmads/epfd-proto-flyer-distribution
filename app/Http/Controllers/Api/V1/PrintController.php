<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\CheckoutService;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PayRequest;
use App\Http\Resources\ArtworkResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PrintOrderResource;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Support\BusinessDays;
use App\Support\Reference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

/**
 * @tags Print
 */
class PrintController extends Controller
{
    use ScopesToBusiness;

    /**
     * Print product catalogue.
     *
     * @unauthenticated
     */
    public function products(): JsonResponse
    {
        $products = PrintProduct::where('is_active', true)->orderBy('sort')->get();

        return response()->json(['data' => $products->map(fn (PrintProduct $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'category' => $p->category,
            'size_label' => $p->size_label,
            'width_mm' => $p->width_mm,
            'height_mm' => $p->height_mm,
            'bleed_mm' => $p->bleed_mm,
            'from_price_cents' => $p->from_price_cents,
            'distributable' => (bool) $p->distributable,
            'turnaround_days' => $p->turnaround_days,
        ])->values()]);
    }

    /**
     * Options for a product: paper weights, finishes per gsm, sides, quantities and the price matrix
     * (`unit_price_hc` = hundredths of a cent per piece, ex GST, before finish surcharge).
     *
     * @unauthenticated
     */
    public function options(int $product): JsonResponse
    {
        $p = PrintProduct::where('is_active', true)->findOrFail($product);
        $card = PricingFactory::rateCard()['print'] ?? [];
        $rows = $p->options()->orderBy('gsm')->orderBy('sides')->orderBy('quantity')->get(['gsm', 'sides', 'quantity', 'unit_price_hc']);

        return response()->json(['data' => [
            'product_id' => $p->id,
            'gsm' => $p->gsm_options,
            'finishes' => $p->finish_options,
            'finish_surcharges' => collect($card['finishes'] ?? [])->map(fn ($f) => ['label' => $f['label'], 'pct' => $f['pct']]),
            'sides' => [1, 2],
            'quantities' => $p->quantity_options,
            'addons' => collect($card['addons'] ?? [])->map(fn ($a) => ['label' => $a['label']] + array_intersect_key($a, ['pct' => 1, 'fixed' => 1])),
            'matrix' => $rows->map(fn ($r) => ['gsm' => $r->gsm, 'sides' => $r->sides, 'quantity' => $r->quantity, 'unit_price_hc' => $r->unit_price_hc])->values(),
        ]]);
    }

    /** List your print orders. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', PrintOrder::STATUSES)]]);

        return PrintOrderResource::collection(
            $this->business($request)->printOrders()->with('product')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Create a draft print order (priced server-side when product/gsm/sides/quantity are given). */
    public function store(Request $request, PricingService $pricing): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate($this->rules(true));
        $order = new PrintOrder([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'reference' => Reference::make('PRT', 'print_orders'),
            'status' => 'draft',
            'delivery_name' => $business->contact_name,
            'delivery_address' => $business->address,
            'delivery_phone' => $business->mobile,
        ]);
        $this->apply($order, $data, $pricing);

        return response()->json(['data' => PrintOrderResource::make($order->fresh()->load('product', 'artworks'))], 201);
    }

    /** Show a print order with artworks. */
    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json(['data' => PrintOrderResource::make($this->find($request, $order)->load('product', 'artworks'))]);
    }

    /** Update a draft print order (re-priced). */
    public function update(Request $request, int $order, PricingService $pricing): JsonResponse
    {
        $model = $this->draft($request, $order);
        $this->apply($model, $request->validate($this->rules(false)), $pricing);

        return response()->json(['data' => PrintOrderResource::make($model->fresh()->load('product', 'artworks'))]);
    }

    /** Delete a draft print order. */
    public function destroy(Request $request, int $order): JsonResponse
    {
        $this->draft($request, $order)->delete();

        return response()->json(null, 204);
    }

    /** Upload artwork (multipart: `file` PDF/PNG/JPEG ≤ 50 MB, `side` front|back). Replaces the same side. */
    public function uploadArtwork(Request $request, int $order): JsonResponse
    {
        $model = $this->draft($request, $order);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'mimetypes:application/pdf,image/png,image/jpeg', 'max:51200'],
            'side' => ['required', 'in:front,back'],
        ]);
        $file = $request->file('file');
        $path = $file->store("artworks/{$model->business_id}/{$model->id}", 'local');
        [$w, $h] = str_starts_with((string) $file->getMimeType(), 'image/') ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];

        foreach ($model->artworks()->where('side', $data['side'])->get() as $old) {
            Storage::disk('local')->delete($old->path);
            $old->delete();
        }
        $art = $model->artworks()->create([
            'side' => $data['side'],
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width_px' => $w,
            'height_px' => $h,
            'status' => 'pending',
        ]);

        return response()->json(['data' => ArtworkResource::make($art)], 201);
    }

    /** Remove an artwork file from a draft order. */
    public function destroyArtwork(Request $request, int $order, int $artwork): JsonResponse
    {
        $art = $this->draft($request, $order)->artworks()->findOrFail($artwork);
        Storage::disk('local')->delete($art->path);
        $art->delete();

        return response()->json(null, 204);
    }

    /** Pay for a print order. Requires a priced order, front artwork and `right_to_print`. */
    public function pay(PayRequest $request, int $order, CheckoutService $checkout): JsonResponse
    {
        $model = $this->find($request, $order);
        if ($model->status === 'draft') {
            $errors = [];
            if (! $model->quantity || $model->total_cents <= 0) {
                $errors['quantity'] = ['Choose product options first.'];
            }
            if (! $model->artworks()->where('side', 'front')->exists()) {
                $errors['artwork'] = ['Upload front artwork.'];
            }
            if (! $model->right_to_print) {
                $errors['right_to_print'] = ['Confirm you have the right to print this artwork.'];
            }
            if ($errors) {
                throw new DomainProblem('validation_failed', 'The print order is not ready for payment.', 422, 'Validation failed', ['errors' => $errors]);
            }
        }
        $payment = $checkout->pay($model, $request->user(), $request->checkoutInput());

        return response()->json(['data' => [
            'payment' => PaymentResource::make($payment->fresh()),
            'print_order' => PrintOrderResource::make($model->fresh()),
            'invoice_id' => $payment->invoice?->id,
        ]], 201);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $s = $creating ? [] : ['sometimes'];

        return [
            'print_product_id' => [...$s, 'required', 'integer', 'exists:print_products,id'],
            'gsm' => [...$s, 'nullable', 'integer'],
            'finish' => [...$s, 'nullable', 'in:matt,gloss'],
            'sides' => [...$s, 'integer', 'in:1,2'],
            'orientation' => [...$s, 'in:portrait,landscape'],
            'quantity' => [...$s, 'nullable', 'integer', 'min:1'],
            'addons' => [...$s, 'nullable', 'array'],
            'addons.*' => ['string', 'in:express,hard_proof'],
            'promo_code' => [...$s, 'nullable', 'string', 'max:40'],
            'delivery_name' => [...$s, 'nullable', 'string', 'max:120'],
            'delivery_address' => [...$s, 'nullable', 'string', 'max:255'],
            'delivery_phone' => [...$s, 'nullable', 'string', 'max:20'],
            'also_distribute' => [...$s, 'boolean'],
            'right_to_print' => [...$s, 'boolean'],
            'step' => [...$s, 'integer', 'min:1', 'max:5'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function apply(PrintOrder $order, array $data, PricingService $pricing): void
    {
        $addons = $data['addons'] ?? ($order->pricing['meta']['addons'] ?? []);
        unset($data['addons']);
        $order->fill($data);
        $product = $order->print_product_id ? PrintProduct::find($order->print_product_id) : null;
        if ($product && $order->gsm && $order->quantity) {
            $quote = $pricing->print([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'gsm' => (int) $order->gsm,
                'sides' => (int) $order->sides,
                'quantity' => (int) $order->quantity,
                'finish' => $order->finish ?: 'matt',
                'addons' => $addons,
                'promo' => PricingFactory::promo($order->promo_code, 'print'),
            ]);
            $arr = $quote->toArray();
            $arr['meta']['addons'] = $addons;
            $order->subtotal_cents = $quote->subtotalCents;
            $order->gst_cents = $quote->gstCents;
            $order->total_cents = $quote->totalCents;
            $order->pricing = $arr;
            $order->earliest_delivery = BusinessDays::add((int) $arr['meta']['turnaround_days'] + (in_array('express', $addons, true) ? -2 : 0) + 2)->toDateString();
        }
        $order->save();
    }

    private function find(Request $request, int $id): PrintOrder
    {
        return $this->business($request)->printOrders()->findOrFail($id);
    }

    private function draft(Request $request, int $id): PrintOrder
    {
        $order = $this->find($request, $id);
        if ($order->status !== 'draft') {
            throw new DomainProblem('conflict', 'Only draft print orders can be changed.', 409, 'Conflict');
        }

        return $order;
    }
}
