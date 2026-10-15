<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\CheckoutService;
use App\Models\Business;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends AdminController
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $q = trim($request->string('q')->toString());
        $rows = Business::query()
            ->when(in_array($status, ['pending', 'approved', 'suspended'], true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")->orWhere('abn', 'like', '%'.preg_replace('/\D/', '', $q).'%')->orWhere('contact_name', 'ilike', "%{$q}%")))
            ->withCount(['campaigns', 'printOrders'])
            ->orderByRaw("status = 'pending' DESC")->latest('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('admin/businesses/index', [
            'businesses' => $rows,
            'filters' => ['status' => $status ?: null, 'q' => $q ?: null],
            'counts' => Business::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function show(Business $business): Response
    {
        $business->load(['users:id,business_id,name,email,mobile,email_verified_at,last_login_at,created_at']);

        return Inertia::render('admin/businesses/show', [
            'business' => $business,
            'credit_balance_cents' => $business->creditBalanceCents(),
            'ledger' => $business->creditEntries()->with('creator:id,name')->latest('id')->limit(100)->get(),
            'campaigns' => $business->campaigns()->latest('id')->limit(50)->get(['id', 'reference', 'name', 'status', 'letterboxes', 'total_cents', 'created_at']),
            'print_orders' => $business->printOrders()->with('product:id,name')->latest('id')->limit(50)->get(['id', 'reference', 'print_product_id', 'status', 'quantity', 'total_cents', 'created_at']),
            'payments' => $business->payments()->latest('id')->limit(50)->get(['id', 'payable_type', 'payable_id', 'gateway', 'amount_cents', 'credits_applied_cents', 'refunded_cents', 'status', 'card_brand', 'card_last4', 'paid_at', 'created_at']),
        ]);
    }

    public function status(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,suspend,reinstate'],
            'note' => [$request->input('action') === 'suspend' ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);
        $to = ['approve' => 'approved', 'suspend' => 'suspended', 'reinstate' => 'approved'][$data['action']];
        $from = $business->status;
        $business->forceFill([
            'status' => $to,
            'status_note' => $data['note'] ?? null,
            'approved_at' => $to === 'approved' ? ($business->approved_at ?? now()) : $business->approved_at,
            'approved_by' => $to === 'approved' ? $request->user()->id : $business->approved_by,
        ])->save();
        Audit::log('business.'.$data['action'], $business, ['from' => $from, 'to' => $to, 'note' => $data['note'] ?? null]);

        return back()->with('success', "{$business->name} ".['approve' => 'approved', 'suspend' => 'suspended', 'reinstate' => 'reinstated'][$data['action']].'.');
    }

    public function credit(Request $request, Business $business, CheckoutService $checkout): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:add,deduct'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $cents = (int) round($data['amount'] * 100) * ($data['direction'] === 'deduct' ? -1 : 1);

        return $this->attempt(function () use ($business, $checkout, $cents, $data, $request) {
            $entry = $checkout->adjustCredits($business, $cents, $data['reason'], null, $request->user());
            Audit::log('business.credit_adjusted', $business, ['amount_cents' => $cents, 'reason' => $data['reason'], 'balance_after_cents' => $entry->balance_after_cents]);
        }, 'Credit '.($cents > 0 ? 'added' : 'deducted').'.');
    }
}
