<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\CheckoutService;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Admin · Payments
 */
class PaymentController extends Controller
{
    use ScopesToBusiness;

    /** All payments (filter `status`, `business_id`, `gateway`). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,succeeded,failed,refunded,partially_refunded'],
            'business_id' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'in:mock,stripe,credits'],
        ]);

        return PaymentResource::collection(
            Payment::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('business_id'), fn ($q, $b) => $q->where('business_id', $b))
                ->when($request->query('gateway'), fn ($q, $g) => $q->where('gateway', $g))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Refund a payment (partial allowed; `as_credit` refunds to the account credit balance). */
    public function refund(Request $request, int $payment, CheckoutService $checkout): JsonResponse
    {
        $p = Payment::whereIn('status', ['succeeded', 'partially_refunded'])->findOrFail($payment);
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'as_credit' => ['boolean'],
        ]);

        return response()->json(['data' => PaymentResource::make(
            $checkout->refund($p, (int) $data['amount_cents'], $request->user(), $data['reason'], (bool) ($data['as_credit'] ?? false))
        )]);
    }
}
