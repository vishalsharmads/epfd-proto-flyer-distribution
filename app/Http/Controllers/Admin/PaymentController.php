<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\CheckoutService;
use App\Http\Controllers\App\Support\Documents;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends AdminController
{
    private function paymentsQuery(Request $request): Builder
    {
        [$from, $to] = $this->range($request->input('from'), $request->input('to'));
        $tz = config('epfd.timezone');

        return Payment::query()->with(['business:id,name', 'payable', 'invoice:id,payment_id,number'])
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->input('status')))
            ->when($request->filled('gateway'), fn ($w) => $w->where('gateway', $request->input('gateway')))
            ->when($from, fn ($w) => $w->where('created_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc()))
            ->when($to, fn ($w) => $w->where('created_at', '<=', Carbon::parse($to, $tz)->endOfDay()->utc()))
            ->when($request->filled('q'), fn ($w) => $w->where(fn ($x) => $x->where('gateway_ref', 'ilike', '%'.$request->input('q').'%')->orWhereHas('business', fn ($b) => $b->where('name', 'ilike', '%'.$request->input('q').'%'))))
            ->latest('id');
    }

    private function invoicesQuery(Request $request): Builder
    {
        [$from, $to] = $this->range($request->input('from'), $request->input('to'));
        $tz = config('epfd.timezone');

        return Invoice::query()->with('business:id,name')
            ->when($request->filled('invoice_status'), fn ($w) => $w->where('status', $request->input('invoice_status')))
            ->when($from, fn ($w) => $w->where('issued_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc()))
            ->when($to, fn ($w) => $w->where('issued_at', '<=', Carbon::parse($to, $tz)->endOfDay()->utc()))
            ->when($request->filled('q'), fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', '%'.$request->input('q').'%')->orWhereHas('business', fn ($b) => $b->where('name', 'ilike', '%'.$request->input('q').'%'))))
            ->latest('id');
    }

    public function index(Request $request): InertiaResponse
    {
        $tab = $request->input('tab') === 'invoices' ? 'invoices' : 'payments';

        return Inertia::render('admin/payments/index', [
            'tab' => $tab,
            'payments' => $tab === 'payments' ? $this->paymentsQuery($request)->paginate(20)->withQueryString()->through(fn (Payment $p) => [
                ...$p->only(['id', 'gateway', 'gateway_ref', 'amount_cents', 'credits_applied_cents', 'refunded_cents', 'status', 'card_brand', 'card_last4', 'failure_reason', 'paid_at', 'created_at']),
                'business' => $p->business?->only(['id', 'name']),
                'payable' => ['type' => class_basename((string) $p->payable_type), 'id' => $p->payable_id, 'reference' => $p->payable?->reference],
                'invoice' => $p->invoice?->only(['id', 'number']),
            ]) : null,
            'invoices' => $tab === 'invoices' ? $this->invoicesQuery($request)->paginate(20)->withQueryString() : null,
            'filters' => $request->only(['status', 'gateway', 'from', 'to', 'q', 'invoice_status']),
            'gateways' => Payment::distinct()->pluck('gateway'),
        ]);
    }

    public function refund(Request $request, Payment $payment, CheckoutService $checkout): RedirectResponse
    {
        $max = ($payment->amount_cents + $payment->credits_applied_cents - $payment->refunded_cents) / 100;
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$max],
            'method' => ['required', 'in:card,credit'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return $this->attempt(
            fn () => $checkout->refund($payment, (int) round($data['amount'] * 100), $request->user(), $data['reason'], $data['method'] === 'credit'),
            'Refund of $'.number_format((float) $data['amount'], 2).' processed '.($data['method'] === 'credit' ? 'as account credit.' : 'to the card.'),
        );
    }

    public function exportPayments(Request $request): StreamedResponse
    {
        $rows = (function () use ($request) {
            foreach ($this->paymentsQuery($request)->lazyById(500) as $p) {
                yield [$p->id, $p->created_at?->tz(config('epfd.timezone'))->format('Y-m-d H:i'), $p->business?->name, class_basename((string) $p->payable_type), $p->payable?->reference,
                    $p->gateway, $p->gateway_ref, $p->status, Csv::money($p->amount_cents), Csv::money($p->credits_applied_cents), Csv::money($p->refunded_cents),
                    $p->card_brand, $p->card_last4 ? '•••• '.$p->card_last4 : null, $p->invoice?->number];
            }
        })();

        return Csv::stream('epfd-payments-'.now()->format('Ymd-His').'.csv',
            ['ID', 'Created (AEST/AEDT)', 'Business', 'For', 'Reference', 'Gateway', 'Gateway ref', 'Status', 'Card charged (AUD)', 'Credits applied (AUD)', 'Refunded (AUD)', 'Card brand', 'Card', 'Invoice'], $rows);
    }

    public function exportInvoices(Request $request): StreamedResponse
    {
        $rows = (function () use ($request) {
            foreach ($this->invoicesQuery($request)->lazyById(500) as $i) {
                yield [$i->number, $i->issued_at?->tz(config('epfd.timezone'))->format('Y-m-d'), $i->business?->name, $i->status,
                    Csv::money($i->subtotal_cents), Csv::money($i->gst_cents), Csv::money($i->total_cents), Csv::money($i->credits_applied_cents)];
            }
        })();

        return Csv::stream('epfd-invoices-'.now()->format('Ymd-His').'.csv',
            ['Invoice', 'Issued', 'Business', 'Status', 'Subtotal ex GST (AUD)', 'GST (AUD)', 'Total incl GST (AUD)', 'Credits applied (AUD)'], $rows);
    }

    /** Invoice PDF via the business agent's Blade view (dompdf), or a plain HTML fallback. */
    public function invoicePdf(Request $request, Invoice $invoice): Response
    {
        $invoice->load(['business', 'payment', 'invoiceable']);
        if (! $request->boolean('html') && class_exists(Documents::class)) {
            try {
                return Documents::invoicePdf($invoice)->stream("{$invoice->number}.pdf");
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->view('admin.invoice-fallback', ['invoice' => $invoice]);
    }
}
