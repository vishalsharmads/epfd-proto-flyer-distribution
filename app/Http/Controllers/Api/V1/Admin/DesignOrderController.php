<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Design\DesignService;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\DesignAssetResource;
use App\Http\Resources\DesignOrderResource;
use App\Models\DesignOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Admin · Design orders
 */
class DesignOrderController extends Controller
{
    use ScopesToBusiness;

    public function __construct(private readonly DesignService $design) {}

    /** All submitted design orders (filter `status`, `design_type`, `business_id`). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:'.implode(',', DesignOrder::STATUSES)],
            'design_type' => ['nullable', 'in:'.implode(',', DesignOrder::TYPES)],
            'business_id' => ['nullable', 'integer'],
        ]);

        return DesignOrderResource::collection(
            DesignOrder::query()->where('status', '!=', 'draft')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('design_type'), fn ($q, $t) => $q->where('design_type', $t))
                ->when($request->query('business_id'), fn ($q, $b) => $q->where('business_id', $b))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Design order detail with brief files, proofs and final files. */
    public function show(int $order): JsonResponse
    {
        return response()->json(['data' => DesignOrderResource::make(DesignOrder::with('assets')->findOrFail($order))]);
    }

    /** Move a design order: `in_design`, `completed` (needs final files) or `cancelled` (needs a `note`). */
    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $model = DesignOrder::findOrFail($order);
        $data = $request->validate([
            'status' => ['required', 'in:in_design,completed,cancelled'],
            'note' => ['required_if:status,cancelled', 'nullable', 'string', 'max:1000'],
        ]);
        $this->design->transition($model, $data['status'], $data['note'] ?? null);

        return response()->json(['data' => DesignOrderResource::make($model->fresh()->load('assets'))]);
    }

    /** Upload a new proof version (multipart `file`: PDF/PNG/JPG ≤ 20 MB, optional `note`). Sets `proof_ready` and emails the business. */
    public function uploadProof(Request $request, int $order): JsonResponse
    {
        $model = DesignOrder::findOrFail($order);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:20480'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $asset = $this->design->uploadProof($model, $request->file('file'), $request->user(), $data['note'] ?? null);

        return response()->json(['data' => ['asset' => DesignAssetResource::make($asset), 'design_order' => DesignOrderResource::make($model->fresh())]], 201);
    }
}
