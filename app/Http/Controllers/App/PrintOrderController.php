<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Payments\CheckoutService;
use App\Domain\Pricing\PricingFactory;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\App\Support\HandlesProblems;
use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\App\Support\PrintPricer;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PayRequest;
use App\Models\Artwork;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Support\Reference;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Print store hub + wizard: Start → Product → Specs → Confirm. */
class PrintOrderController extends Controller
{
    use AuthorizesRequests;
    use HandlesProblems;

    private const STEPS = ['product' => 2, 'specs' => 3, 'confirm' => 4];

    public function hub(Request $request): Response
    {
        $business = $request->user()->business;

        return Inertia::render('app/print/index', [
            'orders' => PrintOrder::where('business_id', $business->id)->with('product.printCategory', 'artworks')->latest('updated_at')->limit(50)->get()->map(fn ($o) => Presenter::printOrder($o)),
            'products' => PrintProduct::forSale()->get()->map(fn ($p) => Presenter::product($p)),
        ]);
    }

    public function start(Request $request): Response
    {
        $business = $request->user()->business;
        $orders = PrintOrder::where('business_id', $business->id)->with('product.printCategory', 'artworks')->latest('updated_at')->limit(30)->get();

        return Inertia::render('app/print/start', [
            'drafts' => $orders->where('status', 'draft')->values()->map(fn ($o) => Presenter::printOrder($o)),
            'past' => $orders->where('status', '!=', 'draft')->values()->map(fn ($o) => Presenter::printOrder($o)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['nullable', 'integer', 'exists:print_products,id']]);
        $order = PrintOrder::create([
            'business_id' => $request->user()->business_id,
            'user_id' => $request->user()->id,
            'reference' => Reference::make('PRT', 'print_orders'),
            'status' => 'draft',
            'step' => 2,
        ]);
        if (! empty($data['product_id'])) {
            $this->applyProduct($order, PrintProduct::forSale()->findOrFail($data['product_id']));

            return redirect()->route('app.print.step', [$order, 'specs']);
        }

        return redirect()->route('app.print.step', [$order, 'product']);
    }

    public function reorder(Request $request, PrintOrder $printOrder): RedirectResponse
    {
        $this->authorize('view', $printOrder);
        $copy = $printOrder->replicate(['reference', 'status', 'step', 'submitted_at', 'paid_at', 'completed_at', 'campaign_id', 'tracking_number', 'vendor', 'proof_path', 'rating', 'rating_comment', 'right_to_print']);
        $copy->forceFill(['reference' => Reference::make('PRT', 'print_orders'), 'status' => 'draft', 'step' => 3, 'source_order_id' => $printOrder->id, 'user_id' => $request->user()->id])->save();
        foreach ($printOrder->artworks as $a) {
            $path = 'artworks/'.$copy->id.'/'.basename($a->path);
            if (Storage::disk('local')->exists($a->path)) {
                Storage::disk('local')->copy($a->path, $path);
                $copy->artworks()->create([...$a->only('side', 'original_name', 'mime', 'size_bytes', 'width_px', 'height_px'), 'path' => $path, 'status' => 'pending']);
            }
        }
        PrintPricer::reprice($copy, PrintPricer::addons($printOrder));

        return redirect()->route('app.print.step', [$copy, 'specs'])->with('success', "Re-order created from {$printOrder->reference}. Check the details and artwork.");
    }

    public function destroy(PrintOrder $printOrder): RedirectResponse
    {
        $this->authorize('delete', $printOrder);
        $printOrder->delete();

        return back()->with('success', 'Draft deleted.');
    }

    public function step(Request $request, PrintOrder $printOrder, string $step): Response|RedirectResponse
    {
        $this->authorize('view', $printOrder);
        if ($printOrder->status !== 'draft') {
            return redirect()->route('app.jobs.print', $printOrder);
        }
        if ($step !== 'product' && ! $printOrder->print_product_id) {
            return redirect()->route('app.print.step', [$printOrder, 'product']);
        }
        if ($printOrder->step < self::STEPS[$step]) {
            $printOrder->forceFill(['step' => self::STEPS[$step]])->saveQuietly();
        }
        $business = $request->user()->business;

        return Inertia::render('app/print/'.$step, [
            'order' => Presenter::printOrder($printOrder->fresh()),
            'products' => $step === 'product' ? PrintProduct::forSale()->get()->map(fn ($p) => Presenter::product($p)) : null,
            'addons' => PrintPricer::addonOptions(),
            'finishes' => collect(PricingFactory::rateCard()['print']['finishes'] ?? [])->map(fn ($f, $k) => ['value' => (string) $k, 'label' => (string) $f['label']])->values(),
            'payment' => $step === 'confirm' ? Presenter::paymentContext($business) : null,
            'delivery_defaults' => ['name' => $business->contact_name, 'address' => $business->address, 'phone' => $business->mobile],
            'upload' => ['max_mb' => 50, 'mimes' => ['application/pdf', 'image/png', 'image/jpeg']],
        ]);
    }

    public function update(Request $request, PrintOrder $printOrder): JsonResponse
    {
        $this->authorize('update', $printOrder);
        $data = $request->validate([
            'product_id' => ['sometimes', 'integer', 'exists:print_products,id'],
            'gsm' => ['sometimes', 'integer'],
            'finish' => ['sometimes', 'string', 'max:40'],
            'sides' => ['sometimes', 'in:1,2'],
            'orientation' => ['sometimes', 'in:portrait,landscape'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'addons' => ['sometimes', 'array'],
            'addons.*' => ['in:'.implode(',', array_column(PrintPricer::addonOptions(), 'value'))],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'also_distribute' => ['sometimes', 'boolean'],
            'delivery_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_phone' => ['sometimes', 'nullable', 'regex:/^[\d\s()+-]{8,20}$/'],
        ], ['delivery_phone.regex' => 'Enter a valid phone number.']);

        $addons = $data['addons'] ?? PrintPricer::addons($printOrder);
        if (isset($data['product_id']) && (int) $data['product_id'] !== $printOrder->print_product_id) {
            $this->applyProduct($printOrder, PrintProduct::forSale()->findOrFail($data['product_id']));
            $printOrder->refresh();
        }
        $p = $printOrder->product;
        $errors = [];
        if ($p) {
            if (isset($data['gsm']) && ! in_array((int) $data['gsm'], array_map('intval', $p->gsm_options), true)) {
                $errors['gsm'] = ['That paper weight is not available for this product.'];
            }
            if (isset($data['quantity']) && ! in_array((int) $data['quantity'], array_map('intval', $p->quantity_options), true)) {
                $errors['quantity'] = ['Choose one of the listed quantities.'];
            }
            if (isset($data['sides']) && ! in_array((int) $data['sides'], $p->sides(), true)) {
                $errors['sides'] = [$p->sides() === [1] ? 'This product is single-sided only.' : 'This product is double-sided only.'];
            }
            $gsm = (int) ($data['gsm'] ?? $printOrder->gsm);
            if (isset($data['finish']) && ! in_array($data['finish'], $p->finishesFor($gsm), true)) {
                $errors['finish'] = ['That finish is not available for this paper weight.'];
            }
            if (($data['also_distribute'] ?? false) && ! $p->distributable) {
                $errors['also_distribute'] = ['This product cannot be letterboxed.'];
            }
        }
        if (array_key_exists('promo_code', $data) && $data['promo_code']) {
            try {
                PricingFactory::promo($data['promo_code'], 'print');
            } catch (DomainProblem $e) {
                $errors['promo_code'] = [$e->getMessage()];
            }
        }
        if ($errors) {
            return response()->json(['detail' => 'Please check the highlighted fields.', 'errors' => $errors], 422);
        }

        $printOrder->fill(array_intersect_key($data, array_flip(['gsm', 'finish', 'sides', 'orientation', 'quantity', 'promo_code', 'also_distribute', 'delivery_name', 'delivery_address', 'delivery_phone'])));
        // Finish availability depends on paper weight: fall back to the first allowed finish.
        if ($p && $printOrder->gsm) {
            $allowed = (array) ($p->finish_options[(string) $printOrder->gsm] ?? ['matt']);
            if (! in_array($printOrder->finish, $allowed, true)) {
                $printOrder->finish = $allowed[0];
            }
        }
        if ((int) $printOrder->sides === 1) {
            $printOrder->artworks()->where('side', 'back')->get()->each(fn (Artwork $a) => $this->removeArtwork($a));
        }
        $printOrder->save();

        try {
            PrintPricer::reprice($printOrder, $addons);
        } catch (DomainProblem $e) {
            return $this->problemJson($e);
        }

        return response()->json(['data' => Presenter::printOrder($printOrder->fresh())]);
    }

    public function upload(Request $request, PrintOrder $printOrder): JsonResponse
    {
        $this->authorize('update', $printOrder);
        $data = $request->validate([
            'side' => ['required', 'in:front,back'],
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/png,image/jpeg', 'mimes:pdf,png,jpg,jpeg', 'max:51200'],
        ], [
            'file.mimetypes' => 'Upload a PDF, PNG or JPG file.',
            'file.mimes' => 'Upload a PDF, PNG or JPG file.',
            'file.max' => 'Files can be at most 50 MB.',
        ]);
        if ($data['side'] === 'back' && (int) $printOrder->sides !== 2) {
            return response()->json(['detail' => 'Switch to double-sided to upload a back.', 'errors' => ['file' => ['Switch to double-sided to upload a back.']]], 422);
        }
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        [$w, $h] = str_starts_with($mime, 'image/') ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];
        $path = $file->store('artworks/'.$printOrder->id, 'local');

        $printOrder->artworks()->where('side', $data['side'])->get()->each(fn (Artwork $a) => $this->removeArtwork($a));
        $artwork = $printOrder->artworks()->create([
            'side' => $data['side'],
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'width_px' => $w,
            'height_px' => $h,
            'status' => 'pending',
        ]);

        return response()->json(['data' => Presenter::artwork($printOrder, $artwork)], 201);
    }

    public function deleteArtwork(PrintOrder $printOrder, Artwork $artwork): JsonResponse
    {
        $this->authorize('update', $printOrder);
        abort_unless($artwork->print_order_id === $printOrder->id, 404);
        $this->removeArtwork($artwork);

        return response()->json(null, 204);
    }

    public function artwork(Request $request, PrintOrder $printOrder, Artwork $artwork): StreamedResponse
    {
        $this->authorize('view', $printOrder);
        abort_unless($artwork->print_order_id === $printOrder->id && Storage::disk('local')->exists($artwork->path), 404);

        return Storage::disk('local')->response($artwork->path, $artwork->original_name, ['Content-Type' => $artwork->mime], $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function pay(PayRequest $request, PrintOrder $printOrder, CheckoutService $checkout): RedirectResponse
    {
        $this->authorize('pay', $printOrder);
        $errors = [];
        if (! $printOrder->artworks()->where('side', 'front')->exists()) {
            $errors['artwork'] = 'Upload your front artwork before paying.';
        } elseif ((int) $printOrder->sides === 2 && ! $printOrder->artworks()->where('side', 'back')->exists()) {
            $errors['artwork'] = 'Upload your back artwork (or switch to single-sided).';
        }
        foreach (['delivery_name' => 'Enter a delivery contact name.', 'delivery_address' => 'Enter a delivery address.', 'delivery_phone' => 'Enter a delivery phone number.'] as $f => $msg) {
            if (! $printOrder->{$f}) {
                $errors[$f] = $msg;
            }
        }
        if (! $request->boolean('artwork_checked')) {
            $errors['artwork_checked'] = "Confirm you've checked your artwork and order details.";
        }
        if (! $request->boolean('right_to_print')) {
            $errors['right_to_print'] = 'Confirm you have the right to print the uploaded artwork.';
        }
        if ($errors) {
            return back()->withErrors($errors);
        }
        $printOrder->forceFill(['right_to_print' => true])->save();
        try {
            $checkout->pay($printOrder, $request->user(), $request->checkoutInput());
        } catch (DomainProblem $e) {
            return back()->withErrors($this->problemErrors($e));
        }

        return redirect()->route('app.jobs.print', $printOrder)->with('success', 'Payment received — your print order has been submitted.');
    }

    private function applyProduct(PrintOrder $order, PrintProduct $p): void
    {
        PrintPricer::applyProduct($order, $p);
    }

    private function removeArtwork(Artwork $a): void
    {
        PrintPricer::removeArtwork($a);
    }
}
