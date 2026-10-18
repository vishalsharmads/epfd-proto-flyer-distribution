<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Design\DesignService;
use App\Domain\Payments\CheckoutService;
use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PayRequest;
use App\Http\Resources\DesignAssetResource;
use App\Http\Resources\DesignOrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\DesignAsset;
use App\Models\DesignOrder;
use App\Support\BusinessDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * @tags Design Studio
 */
class DesignController extends Controller
{
    use ScopesToBusiness;

    public function __construct(private readonly DesignService $design) {}

    /**
     * Design services: types, packages (revisions, turnaround), add-ons and ex-GST prices in cents.
     *
     * @unauthenticated
     */
    public function services(): JsonResponse
    {
        return response()->json(['data' => Presenter::designCatalogue()]);
    }

    /**
     * Quote a design order (ex GST lines + GST) from the active rate card.
     *
     * @unauthenticated
     */
    public function quote(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'design_type' => ['required', Rule::in(DesignService::typeKeys())],
            'package' => ['required', Rule::in(DesignService::packageKeys())],
            'sides' => ['nullable', 'integer', 'in:1,2'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['string', 'max:40'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);
        $quote = $pricing->design([
            'design_type' => $data['design_type'],
            'package' => $data['package'],
            'sides' => (int) ($data['sides'] ?? 1),
            'addons' => array_values($data['addons'] ?? []),
            'promo' => PricingFactory::promo($data['promo_code'] ?? null, 'design'),
        ])->toArray();
        $quote['meta']['estimated_first_proof'] = BusinessDays::add((int) $quote['meta']['turnaround_days'])->toDateString();

        return response()->json(['data' => $quote]);
    }

    /** List your design orders (filter `status`). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', DesignOrder::STATUSES)]]);

        return DesignOrderResource::collection(
            $this->business($request)->designOrders()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Create a draft design order (priced server-side). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $order = $this->design->createDraft($this->business($request), $request->user(), $data);

        return response()->json(['data' => DesignOrderResource::make($order->fresh()->load('assets'))], 201);
    }

    /** Show a design order with its brief files, proofs and final files. */
    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json(['data' => DesignOrderResource::make($this->find($request, $order)->load('assets'))]);
    }

    /** Update a draft design order (service, brief, add-ons, promo) — re-priced. */
    public function update(Request $request, int $order): JsonResponse
    {
        $model = $this->find($request, $order);
        $updated = $this->design->update($model, $request->validate($this->rules(false)));

        return response()->json(['data' => DesignOrderResource::make($updated->fresh()->load('assets'))]);
    }

    /** Delete a draft design order. */
    public function destroy(Request $request, int $order): JsonResponse
    {
        $model = $this->find($request, $order);
        if ($model->status !== 'draft') {
            throw new DomainProblem('conflict', 'Only draft design orders can be deleted.', 409, 'Conflict');
        }
        $model->assets()->get()->each(fn (DesignAsset $a) => $this->design->deleteAsset($a));
        $model->delete();

        return response()->json(null, 204);
    }

    /** Upload brief/reference files (multipart `files[]`: PDF, PNG, JPG or SVG, ≤ 20 MB each, max 10 per order). */
    public function uploadAssets(Request $request, int $order): JsonResponse
    {
        $model = $this->find($request, $order);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.DesignService::MAX_BRIEF_FILES],
            'files.*' => ['file', 'mimes:pdf,png,jpg,jpeg,svg', 'max:20480'],
        ]);
        $assets = [];
        foreach ($request->file('files') as $file) {
            $assets[] = $this->design->addBriefUpload($model, $file, $request->user());
        }

        return response()->json(['data' => DesignAssetResource::collection($assets)], 201);
    }

    /** Pay for a draft design order. Requires a headline and key message in the brief. */
    public function pay(PayRequest $request, int $order, CheckoutService $checkout): JsonResponse
    {
        $model = $this->find($request, $order);
        if ($model->status === 'draft' && (! trim((string) $model->headline) || ! trim((string) $model->key_message))) {
            throw new DomainProblem('validation_failed', 'Complete the brief before paying.', 422, 'Validation failed', ['errors' => ['headline' => ['A headline and key message are required.']]]);
        }
        $payment = $checkout->pay($model, $request->user(), $request->checkoutInput());

        return response()->json(['data' => [
            'payment' => PaymentResource::make($payment->fresh()),
            'design_order' => DesignOrderResource::make($model->fresh()),
            'invoice_id' => $payment->invoice?->id,
        ]], 201);
    }

    /** Approve the latest proof (status `proof_ready` → `approved`). */
    public function approve(Request $request, int $order): JsonResponse
    {
        $model = $this->design->approve($this->find($request, $order), $request->user());

        return response()->json(['data' => DesignOrderResource::make($model->fresh()->load('assets'))]);
    }

    /**
     * Request changes to the latest proof (uses one revision). When the package's revisions are used up the
     * request is still accepted and `extra_revision` is true — that revision is invoiced separately.
     */
    public function requestChanges(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['changes' => ['required', 'string', 'min:5', 'max:4000']]);
        $r = $this->design->requestChanges($this->find($request, $order), $request->user(), $data['changes']);

        return response()->json(['data' => [
            'design_order' => DesignOrderResource::make($r['order']->fresh()),
            'extra_revision' => $r['extra_revision'],
            'extra_revision_cents' => $r['extra_revision_cents'],
        ]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $s = $creating ? [] : ['sometimes'];

        return [
            'design_type' => [...$s, $creating ? 'nullable' : 'required', Rule::in(DesignService::typeKeys())],
            'package' => [...$s, $creating ? 'nullable' : 'required', Rule::in(DesignService::packageKeys())],
            'sides' => [...$s, 'integer', 'in:1,2'],
            'print_product_id' => [...$s, 'nullable', 'integer', 'exists:print_products,id'],
            'size' => [...$s, 'nullable', 'string', 'max:120'],
            'addons' => [...$s, 'nullable', 'array'],
            'addons.*' => ['string', 'max:40'],
            'promo_code' => [...$s, 'nullable', 'string', 'max:40'],
            'headline' => [...$s, 'nullable', 'string', 'max:160'],
            'key_message' => [...$s, 'nullable', 'string', 'max:2000'],
            'call_to_action' => [...$s, 'nullable', 'string', 'max:160'],
            'contact_details' => [...$s, 'nullable', 'string', 'max:500'],
            'brand_colours' => [...$s, 'nullable', 'string', 'max:160'],
            'style_notes' => [...$s, 'nullable', 'string', 'max:2000'],
            'target_audience' => [...$s, 'nullable', 'string', 'max:255'],
            'due_date' => [...$s, 'nullable', 'date', 'after_or_equal:today'],
        ];
    }

    private function find(Request $request, int $id): DesignOrder
    {
        return $this->business($request)->designOrders()->findOrFail($id);
    }
}
