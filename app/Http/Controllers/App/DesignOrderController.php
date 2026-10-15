<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Design\DesignService;
use App\Domain\Payments\CheckoutService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\App\Support\HandlesProblems;
use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PayRequest;
use App\Models\DesignAsset;
use App\Models\DesignOrder;
use App\Models\PrintProduct;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Design Studio hub + wizard (Start → Service → Brief → Confirm & pay) and the business's design actions. */
class DesignOrderController extends Controller
{
    use AuthorizesRequests;
    use HandlesProblems;

    private const STEPS = ['service' => 2, 'brief' => 3, 'confirm' => 4];

    public function __construct(private readonly DesignService $design) {}

    public function hub(Request $request): Response
    {
        $business = $request->user()->business;

        return Inertia::render('app/design/index', [
            'orders' => DesignOrder::where('business_id', $business->id)->with('assets')->latest('updated_at')->limit(50)->get()->map(fn ($o) => Presenter::designOrder($o)),
            'catalogue' => Presenter::designCatalogue(),
        ]);
    }

    public function start(Request $request): Response
    {
        $business = $request->user()->business;

        return Inertia::render('app/design/start', [
            'drafts' => DesignOrder::where('business_id', $business->id)->where('status', 'draft')->latest('updated_at')->limit(20)->get()->map(fn ($o) => Presenter::designOrder($o)),
            'catalogue' => Presenter::designCatalogue(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'design_type' => ['nullable', 'in:'.implode(',', DesignOrder::TYPES)],
            'package' => ['nullable', 'in:'.implode(',', DesignOrder::PACKAGES)],
            'print_product_id' => ['nullable', 'integer', 'exists:print_products,id'],
        ]);
        $order = $this->design->createDraft($request->user()->business, $request->user(), array_filter($data));

        return redirect()->route('app.design.step', [$order, 'service']);
    }

    public function destroy(DesignOrder $designOrder): RedirectResponse
    {
        $this->authorize('delete', $designOrder);
        $designOrder->assets()->get()->each(fn (DesignAsset $a) => $this->design->deleteAsset($a));
        $designOrder->delete();

        return back()->with('success', 'Draft deleted.');
    }

    public function step(Request $request, DesignOrder $designOrder, string $step): Response|RedirectResponse
    {
        $this->authorize('view', $designOrder);
        if ($designOrder->status !== 'draft') {
            return redirect()->route('app.jobs.design', $designOrder);
        }
        if ($designOrder->step < self::STEPS[$step]) {
            $designOrder->forceFill(['step' => self::STEPS[$step]])->saveQuietly();
        }

        return Inertia::render('app/design/'.$step, [
            'order' => Presenter::designOrder($designOrder->fresh()),
            'catalogue' => Presenter::designCatalogue(),
            'products' => $step === 'service' ? PrintProduct::where('is_active', true)->whereIn('category', array_keys(DesignService::CATEGORY_TO_TYPE))->orderBy('sort')->get()->map(fn ($p) => Presenter::product($p)) : null,
            'payment' => $step === 'confirm' ? Presenter::paymentContext($request->user()->business) : null,
            'upload' => ['max_mb' => 20, 'max_files' => DesignService::MAX_BRIEF_FILES, 'mimes' => DesignService::BRIEF_MIMES],
        ]);
    }

    /** Autosave (JSON) — re-prices with the shared PricingService. */
    public function update(Request $request, DesignOrder $designOrder): JsonResponse
    {
        $this->authorize('update', $designOrder);
        $data = $request->validate([
            'design_type' => ['sometimes', 'in:'.implode(',', DesignOrder::TYPES)],
            'package' => ['sometimes', 'in:'.implode(',', DesignOrder::PACKAGES)],
            'sides' => ['sometimes', 'integer', 'in:1,2'],
            'print_product_id' => ['sometimes', 'nullable', 'integer', 'exists:print_products,id'],
            'size' => ['sometimes', 'nullable', 'string', 'max:120'],
            'addons' => ['sometimes', 'array'],
            'addons.*' => ['string', 'max:40'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'key_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'call_to_action' => ['sometimes', 'nullable', 'string', 'max:160'],
            'contact_details' => ['sometimes', 'nullable', 'string', 'max:500'],
            'brand_colours' => ['sometimes', 'nullable', 'string', 'max:160'],
            'style_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'target_audience' => ['sometimes', 'nullable', 'string', 'max:255'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
        ], ['due_date.after_or_equal' => 'Choose a due date from today onwards.']);
        if (array_key_exists('print_product_id', $data) && $data['print_product_id'] && ! array_key_exists('size', $data)) {
            $data['size'] = PrintProduct::whereKey($data['print_product_id'])->value('size_label');
        }
        try {
            $order = $this->design->update($designOrder, $data);
        } catch (DomainProblem $e) {
            return $this->problemJson($e);
        }

        return response()->json(['data' => Presenter::designOrder($order->fresh())]);
    }

    /** Upload one or more brief/reference files (PDF, PNG, JPG, SVG ≤ 20 MB each). */
    public function upload(Request $request, DesignOrder $designOrder): JsonResponse
    {
        $this->authorize('view', $designOrder);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.DesignService::MAX_BRIEF_FILES],
            'files.*' => ['file', 'mimes:pdf,png,jpg,jpeg,svg', 'max:20480'],
        ], [
            'files.*.mimes' => 'Upload PDF, PNG, JPG or SVG files.',
            'files.*.max' => 'Each file can be at most 20 MB.',
        ]);
        try {
            $assets = [];
            foreach ($request->file('files') as $file) {
                $assets[] = $this->design->addBriefUpload($designOrder, $file, $request->user());
            }
        } catch (DomainProblem $e) {
            return $this->problemJson($e);
        }

        return response()->json(['data' => array_map(fn ($a) => Presenter::designAsset($designOrder, $a), $assets)], 201);
    }

    public function deleteAsset(DesignOrder $designOrder, DesignAsset $asset): JsonResponse
    {
        $this->authorize('update', $designOrder);
        abort_unless($asset->design_order_id === $designOrder->id && $asset->kind === 'brief_upload', 404);
        $this->design->deleteAsset($asset);

        return response()->json(null, 204);
    }

    public function asset(Request $request, DesignOrder $designOrder, DesignAsset $asset): StreamedResponse
    {
        $this->authorize('view', $designOrder);
        abort_unless($asset->design_order_id === $designOrder->id && Storage::disk('local')->exists($asset->path), 404);

        return self::stream($asset, $request->boolean('download'));
    }

    public function pay(PayRequest $request, DesignOrder $designOrder, CheckoutService $checkout): RedirectResponse
    {
        $this->authorize('pay', $designOrder);
        $errors = [];
        foreach (['headline' => 'Add a headline to your brief.', 'key_message' => 'Tell us the key message for your design.'] as $f => $msg) {
            if (! trim((string) $designOrder->{$f})) {
                $errors[$f] = $msg;
            }
        }
        if ($errors) {
            return back()->withErrors($errors + ['brief' => 'Complete your brief before paying.']);
        }
        try {
            $checkout->pay($designOrder, $request->user(), $request->checkoutInput());
        } catch (DomainProblem $e) {
            return back()->withErrors($this->problemErrors($e));
        }

        return redirect()->route('app.jobs.design', $designOrder)->with('success', 'Payment received — your design brief has been submitted to our studio.');
    }

    public function approve(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $this->authorize('view', $designOrder);
        try {
            $this->design->approve($designOrder, $request->user());
        } catch (DomainProblem $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Design approved — our studio will send the final files shortly.');
    }

    public function requestChanges(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $this->authorize('view', $designOrder);
        $data = $request->validate(['changes' => ['required', 'string', 'min:5', 'max:4000']], ['changes.required' => 'Describe the changes you would like.', 'changes.min' => 'Describe the changes you would like.']);
        try {
            $r = $this->design->requestChanges($designOrder, $request->user(), $data['changes']);
        } catch (DomainProblem $e) {
            return back()->with('error', $e->getMessage());
        }
        $msg = $r['extra_revision']
            ? 'Changes requested. This is beyond the revisions in your package, so an extra revision ('.number_format($r['extra_revision_cents'] / 100, 2).' + GST) will be invoiced.'
            : 'Changes requested — our designer will send a new proof.';

        return back()->with('success', $msg);
    }

    /** "Print this design" / "Print + distribute" → a print-order draft with the design attached as artwork. */
    public function print(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $this->authorize('view', $designOrder);
        $data = $request->validate(['also_distribute' => ['sometimes', 'boolean']]);
        try {
            $print = $this->design->createPrintOrder($designOrder, $request->user(), (bool) ($data['also_distribute'] ?? false));
        } catch (DomainProblem $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('app.print.step', [$print, 'specs'])->with('success', "Print order {$print->reference} created with your design attached. Choose paper and quantity.");
    }

    /** Stream a design file. SVGs are always downloaded (never rendered inline) to avoid script execution. */
    public static function stream(DesignAsset $asset, bool $download): StreamedResponse
    {
        $inline = ! $download && $asset->mime !== 'image/svg+xml';

        $headers = ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff'];
        if ($asset->mime !== 'application/pdf') {
            // PDFs need the browser's viewer; everything else is locked down.
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox";
        }

        return Storage::disk('local')->response($asset->path, $asset->original_name, $headers, $inline ? 'inline' : 'attachment');
    }
}
