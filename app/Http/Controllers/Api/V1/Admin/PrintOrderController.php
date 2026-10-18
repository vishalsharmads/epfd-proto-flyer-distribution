<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\CheckoutService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtworkResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PrintOrderResource;
use App\Models\Artwork;
use App\Models\PrintOrder;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Admin · Print orders
 */
class PrintOrderController extends Controller
{
    use ScopesToBusiness;

    /** All print orders (filter `status`, `business_id`). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', PrintOrder::STATUSES)], 'business_id' => ['nullable', 'integer']]);

        return PrintOrderResource::collection(
            PrintOrder::with('product.printCategory')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('business_id'), fn ($q, $b) => $q->where('business_id', $b))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Print order detail with artworks. */
    public function show(int $order): JsonResponse
    {
        return response()->json(['data' => PrintOrderResource::make(PrintOrder::with('product.printCategory', 'artworks')->findOrFail($order))]);
    }

    /** Update production status, vendor and tracking number. */
    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $o = PrintOrder::findOrFail($order);
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_diff(PrintOrder::STATUSES, ['draft']))],
            'vendor' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
        ]);
        if ($o->status === 'draft') {
            throw new DomainProblem('conflict', 'Unpaid draft orders cannot change status.', 409, 'Conflict');
        }
        $o->fill($data);
        if ($data['status'] === 'completed') {
            $o->completed_at = now();
        }
        $o->save();
        Audit::log('print_order.status', $o, $data);

        return response()->json(['data' => PrintOrderResource::make($o->load('product', 'artworks'))]);
    }

    /** Approve an artwork file. When all artworks are approved an awaiting order moves to `in_printing`. */
    public function approveArtwork(Request $request, int $artwork): JsonResponse
    {
        return $this->review($request, $artwork, 'approved');
    }

    /** Reject an artwork file (a `note` explaining why is required). */
    public function rejectArtwork(Request $request, int $artwork): JsonResponse
    {
        return $this->review($request, $artwork, 'rejected');
    }

    /** Refund the order's payment (full by default; `as_credit` refunds to account credit). */
    public function refund(Request $request, int $order, CheckoutService $checkout): JsonResponse
    {
        $o = PrintOrder::findOrFail($order);
        $payment = $o->payments()->whereIn('status', ['succeeded', 'partially_refunded'])->latest('id')->firstOrFail();
        $data = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'as_credit' => ['boolean'],
        ]);
        $amount = (int) ($data['amount_cents'] ?? ($payment->amount_cents + $payment->credits_applied_cents - $payment->refunded_cents));
        $payment = $checkout->refund($payment, $amount, $request->user(), $data['reason'], (bool) ($data['as_credit'] ?? false));

        return response()->json(['data' => PaymentResource::make($payment)]);
    }

    private function review(Request $request, int $artwork, string $status): JsonResponse
    {
        $art = Artwork::findOrFail($artwork);
        $data = $request->validate(['note' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'max:1000']]);
        $art->forceFill(['status' => $status, 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
        $order = $art->printOrder;
        if ($status === 'approved' && in_array($order->status, ['submitted', 'awaiting_artwork_approval'], true)
            && ! $order->artworks()->where('status', '!=', 'approved')->exists()) {
            $order->update(['status' => 'in_printing']);
        } elseif ($status === 'rejected' && $order->status === 'submitted') {
            $order->update(['status' => 'awaiting_artwork_approval']);
        }
        Audit::log("artwork.{$status}", $art, ['note' => $data['note'] ?? null]);

        return response()->json(['data' => ArtworkResource::make($art)]);
    }
}
