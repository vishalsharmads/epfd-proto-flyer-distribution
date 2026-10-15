<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Payments\Events\OrderPaid;
use App\Exceptions\DomainProblem;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CreditLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PrintOrder;
use App\Models\PromoCode;
use App\Models\SavedCard;
use App\Models\User;
use App\Support\Audit;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pays for a Campaign or PrintOrder: credits first, then the card via the PaymentGateway, then
 * issues a GST invoice, moves the job to "submitted" and records everything in the ledger/audit log.
 */
final class CheckoutService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @param  array{card?: array<string, mixed>|null, saved_card_id?: int|null, save_card?: bool, use_credits?: bool,
     *               billing: array{company:string, abn?:string|null, address:string}, idempotency_key?: string|null}  $input
     */
    public function pay(Campaign|PrintOrder $payable, User $user, array $input): Payment
    {
        /** @var Business $business */
        $business = $payable->business;

        if (! $business->canPay()) {
            throw new DomainProblem('business_not_approved', 'Your business account is awaiting approval. You can save drafts, but payment is blocked until an administrator approves the account.', 403, 'Business not approved');
        }
        if ($payable->status !== 'draft' || $payable->paid_at !== null) {
            throw new DomainProblem('conflict', 'This job has already been submitted.', 409, 'Already paid');
        }
        if ($payable->total_cents <= 0) {
            throw new DomainProblem('validation_failed', 'Nothing to pay — the total is $0.00.', 422);
        }

        $key = $input['idempotency_key'] ?? null;
        if ($key && ($existing = Payment::where('idempotency_key', $key)->first())) {
            return $existing;
        }

        $total = (int) $payable->total_cents;
        $credits = ($input['use_credits'] ?? false) ? min($business->creditBalanceCents(), $total) : 0;
        $toCharge = $total - $credits;
        $billing = [
            'company' => $input['billing']['company'],
            'abn' => $input['billing']['abn'] ?? null,
            'address' => $input['billing']['address'],
        ];

        $card = null;
        if ($toCharge > 0) {
            if (! empty($input['saved_card_id'])) {
                $saved = SavedCard::where('business_id', $business->id)->findOrFail($input['saved_card_id']);
                $card = ['token' => $saved->gateway_token, 'brand' => $saved->brand, 'last4' => $saved->last4];
            } elseif (! empty($input['card'])) {
                $card = $input['card'];
            } else {
                throw new DomainProblem('validation_failed', 'A card is required.', 422);
            }
        }

        $result = $toCharge > 0
            ? $this->gateway->charge($toCharge, 'AUD', $card, $key ?? (string) Str::uuid(), $payable->reference)
            : new ChargeResult(true, 'credits_only');

        if (! $result->succeeded) {
            Payment::create([
                'business_id' => $business->id,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->id,
                'gateway' => $this->gateway->name(),
                'gateway_ref' => $result->gatewayRef,
                'amount_cents' => $toCharge,
                'status' => 'failed',
                'card_brand' => $result->brand,
                'card_last4' => $result->last4,
                'failure_reason' => $result->failureReason,
                'billing' => $billing,
            ]);
            throw new DomainProblem('payment_declined', $result->failureReason ?? 'The payment was declined.', 402, 'Payment declined');
        }

        $payment = DB::transaction(function () use ($payable, $user, $business, $input, $billing, $result, $toCharge, $credits, $card, $key) {
            $payment = Payment::create([
                'business_id' => $business->id,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->id,
                'gateway' => $toCharge > 0 ? $this->gateway->name() : 'credits',
                'gateway_ref' => $result->gatewayRef,
                'idempotency_key' => $key,
                'amount_cents' => $toCharge,
                'credits_applied_cents' => $credits,
                'status' => 'succeeded',
                'card_brand' => $result->brand ?? ($card['brand'] ?? null),
                'card_last4' => $result->last4 ?? ($card['last4'] ?? null),
                'billing' => $billing,
                'paid_at' => now(),
            ]);

            if ($credits > 0) {
                $this->adjustCredits($business, -$credits, 'Applied to '.$payable->reference, $payment, $user);
            }

            if (($input['save_card'] ?? false) && ! empty($input['card'])) {
                $tok = $this->gateway->tokenize($input['card']);
                SavedCard::create([
                    'business_id' => $business->id,
                    'gateway' => $this->gateway->name(),
                    'gateway_token' => $tok['token'],
                    'brand' => $tok['brand'],
                    'last4' => $tok['last4'],
                    'exp_month' => $tok['exp_month'],
                    'exp_year' => $tok['exp_year'],
                    'holder_name' => (string) ($input['card']['name'] ?? $user->name),
                    'is_default' => ! $business->savedCards()->exists(),
                ]);
            }

            $pricing = (array) $payable->pricing;
            Invoice::create([
                'business_id' => $business->id,
                'payment_id' => $payment->id,
                'invoiceable_type' => $payable->getMorphClass(),
                'invoiceable_id' => $payable->id,
                'number' => Reference::invoiceNumber(),
                'lines' => $pricing['lines'] ?? [],
                'billing' => $billing,
                'subtotal_cents' => $payable->subtotal_cents,
                'gst_cents' => $payable->gst_cents,
                'total_cents' => $payable->total_cents,
                'credits_applied_cents' => $credits,
                'status' => 'paid',
                'issued_at' => now(),
            ]);

            if ($payable->promo_code) {
                PromoCode::whereRaw('upper(code) = ?', [strtoupper($payable->promo_code)])->increment('uses');
            }

            $payable->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
                'paid_at' => now(),
            ])->save();

            Audit::log('payment.succeeded', $payable, ['payment_id' => $payment->id, 'amount_cents' => $toCharge, 'credits_cents' => $credits]);

            return $payment;
        });

        event(new OrderPaid($payable->fresh() ?? $payable, $payment));

        return $payment;
    }

    public function refund(Payment $payment, int $amountCents, User $by, string $reason, bool $asCredit = false): Payment
    {
        $refundable = $payment->amount_cents - $payment->refunded_cents;
        if ($amountCents <= 0 || $amountCents > $refundable + $payment->credits_applied_cents) {
            throw new DomainProblem('validation_failed', 'Refund amount exceeds the refundable balance.', 422);
        }

        return DB::transaction(function () use ($payment, $amountCents, $by, $reason, $asCredit, $refundable) {
            if ($asCredit) {
                $this->adjustCredits($payment->business, $amountCents, 'Refund as credit: '.$reason, $payment, $by);
            } else {
                $res = $this->gateway->refund((string) $payment->gateway_ref, min($amountCents, $refundable));
                if (! $res->succeeded) {
                    throw new DomainProblem('refund_failed', $res->failureReason ?? 'Refund failed.', 502);
                }
            }
            $payment->refunded_cents += $amountCents;
            $payment->status = $payment->refunded_cents >= $payment->amount_cents + $payment->credits_applied_cents ? 'refunded' : 'partially_refunded';
            $payment->save();
            if ($payment->status === 'refunded') {
                $payment->invoice?->update(['status' => 'refunded']);
            }
            Audit::log('payment.refunded', $payment, ['amount_cents' => $amountCents, 'as_credit' => $asCredit, 'reason' => $reason]);

            return $payment;
        });
    }

    public function adjustCredits(Business $business, int $amountCents, string $reason, ?Model $ref = null, ?User $by = null): CreditLedger
    {
        return DB::transaction(function () use ($business, $amountCents, $reason, $ref, $by) {
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->first();
            $balance = $business->creditBalanceCents() + $amountCents;
            if ($balance < 0) {
                throw new DomainProblem('validation_failed', 'Insufficient credit balance.', 422);
            }

            return CreditLedger::create([
                'business_id' => $business->id,
                'amount_cents' => $amountCents,
                'balance_after_cents' => $balance,
                'reason' => $reason,
                'reference_type' => $ref?->getMorphClass(),
                'reference_id' => $ref?->getKey(),
                'created_by' => $by?->id,
            ]);
        });
    }
}
