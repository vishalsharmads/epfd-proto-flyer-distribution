<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\CheckoutService;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\App\Support\Documents;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * @tags Payments & invoices
 */
class BillingController extends Controller
{
    use ScopesToBusiness;

    /** Payment history (including failed attempts). */
    public function payments(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:pending,requires_action,succeeded,failed,refunded,partially_refunded']]);

        return PaymentResource::collection(
            $this->business($request)->payments()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Tax invoices. */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        return InvoiceResource::collection($this->business($request)->invoices()->latest('id')->paginate($this->perPage($request)));
    }

    /**
     * Show a tax invoice. `?format=pdf` streams the PDF (application/pdf) when the PDF template is installed.
     */
    public function invoice(Request $request, int $invoice): JsonResponse|Response
    {
        $model = $this->business($request)->invoices()->with('business', 'invoiceable')->findOrFail($invoice);
        if ($request->query('format') === 'pdf' && view()->exists('pdf.invoice')) {
            $pdf = class_exists(Documents::class)
                ? Documents::invoicePdf($model)
                : Pdf::loadView('pdf.invoice', ['invoice' => $model, 'business' => $model->business]);

            return $pdf->download($model->number.'.pdf');
        }

        return response()->json(['data' => InvoiceResource::make($model)]);
    }

    /**
     * Confirm a payment after 3-D Secure.
     *
     * Call this after the browser SDK finished the authentication started by a 402 `payment_action_required`
     * response. Returns the completed payment, 402 `payment_action_required` again while the bank is still waiting,
     * or 402 `payment_declined` when authentication failed.
     */
    public function confirmPayment(Request $request, int $payment, CheckoutService $checkout): JsonResponse
    {
        $model = $checkout->confirm($this->business($request)->payments()->findOrFail($payment));

        return response()->json(['data' => [
            'payment' => PaymentResource::make($model->fresh()),
            'invoice_id' => $model->invoice?->id,
        ]]);
    }
}
