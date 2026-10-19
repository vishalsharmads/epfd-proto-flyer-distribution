<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Payments\Events\OrderPaid;
use App\Exceptions\DomainProblem;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CreditLedger;
use App\Models\DesignOrder;
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
 * Pays for a Campaign, PrintOrder or DesignOrder: credits first, then the card via the active payment gateway,
 * then issues a GST invoice, moves the job to "submitted" and records everything in the ledger/audit log.
 *
 * When the card needs 3-D Secure, pay() records a `requires_action` payment and throws PaymentActionRequired;
 * the browser authenticates and confirm() (or the gateway's webhook) completes the order.
 */
final class CheckoutService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @param  array{card?: array<string, mixed>|null, payment_method?: string|null, saved_card_id?: int|null, save_card?: bool, use_credits?: bool,
     *               billing: array{company:string, abn?:string|null, address:string}, idempotency_key?: string|null}  $input
     */
    public function pay(Campaign|PrintOrder|DesignOrder $payable, User $user, array $input): Payment
    {
        /** @var Business $business */
        $business = $payable->business;

        if (! $business->canPay()) {
            throw new DomainProblem('business_not_approved', 'Your business account is awaiting approval. You can save drafts, but payment is blocked until an administrator approves the account.', 403, 'Business not approved');
        }

        $key = $input['idempotency_key'] ?? null;
        if ($key && ($existing = Payment::where('idempotency_key', $key)->first())) {
            if ($existing->status === 'requires_action') {
                return $this->confirm($existing);
            }

            return $existing;
        }
        if ($payable->status !== 'draft' || $payable->paid_at !== null) {
            throw new DomainProblem('conflict', 'This job has already been submitted.', 409, 'Already paid');
        }
        if ($payable->total_cents <= 0) {
            throw new DomainProblem('validation_failed', 'Nothing to pay — the total is $0.00.', 422);
        }

        $gateway = $this->gateways->driver();
        $total = (int) $payable->total_cents;
        $credits = ($input['use_credits'] ?? false) ? min($business->creditBalanceCents(), $total) : 0;
        $toCharge = $total - $credits;
        $billing = [
            'company' => $input['billing']['company'],
            'abn' => $input['billing']['abn'] ?? null,
            'address' => $input['billing']['address'],
        ];

        $source = null;
        $savedCard = null;
        if ($toCharge > 0) {
            if (! empty($input['saved_card_id'])) {
                $savedCard = SavedCard::where('business_id', $business->id)->where('gateway', $gateway->name())->find($input['saved_card_id']);
                if (! $savedCard) {
                    throw new DomainProblem('validation_failed', 'That saved card can no longer be used. Enter your card details.', 422);
                }
                $source = ['token' => $savedCard->gateway_token];
            } elseif (! empty($input['payment_method'])) {
                $source = ['payment_method' => (string) $input['payment_method']];
            } elseif (! empty($input['card']) && $gateway->name() === 'mock') {
                $source = $input['card'];
            } else {
                throw new DomainProblem('validation_failed', 'A card is required.', 422);
            }
        }
        $saveCard = ($input['save_card'] ?? false) && $savedCard === null && $source !== null;

        $result = $toCharge > 0
            ? $gateway->charge($toCharge, 'AUD', $source, $key ?? (string) Str::uuid(), $payable->reference, $business, $saveCard)
            : new ChargeResult(true, 'credits_only');

        $attributes = [
            'business_id' => $business->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->id,
            'gateway' => $toCharge > 0 ? $gateway->name() : 'credits',
            'gateway_ref' => $result->gatewayRef,
            'amount_cents' => $toCharge,
            'card_brand' => $result->brand ?? $savedCard?->brand,
            'card_last4' => $result->last4 ?? $savedCard?->last4,
            'billing' => $billing,
        ];

        if ($result->requiresAction) {
            $payment = Payment::create([...$attributes,
                'idempotency_key' => $key,
                'credits_applied_cents' => $credits,
                'status' => 'requires_action',
                'meta' => ['user_id' => $user->id, 'save_card' => $saveCard, 'source' => self::storableSource($source)],
            ]);
            Audit::log('payment.requires_action', $payable, ['payment_id' => $payment->id]);

            throw new PaymentActionRequired($payment, (string) $result->clientSecret);
        }

        if (! $result->succeeded) {
            Payment::create([...$attributes, 'status' => 'failed', 'failure_reason' => $result->failureReason]);
            throw new DomainProblem('payment_declined', $result->failureReason ?? 'The payment was declined.', 402, 'Payment declined');
        }

        $payment = Payment::create([...$attributes,
            'idempotency_key' => $key,
            'credits_applied_cents' => $credits,
            'status' => 'pending',
            'meta' => ['user_id' => $user->id],
        ]);

        return $this->complete($payment, $saveCard ? $source : null, $result);
    }

    /**
     * Finish a payment that needed 3-D Secure, after the customer authenticated in the browser.
     * Throws PaymentActionRequired again while the bank still waits, or payment_declined when it failed.
     */
    public function confirm(Payment $payment): Payment
    {
        if ($payment->status === 'succeeded') {
            return $payment;
        }
        if ($payment->status !== 'requires_action') {
            throw new DomainProblem('conflict', 'This payment can no longer be confirmed. Please pay again.', 409, 'Payment closed');
        }

        $result = $this->gateways->driver($payment->gateway)->retrieve((string) $payment->gateway_ref);
        if ($result->requiresAction) {
            throw new PaymentActionRequired($payment, (string) $result->clientSecret);
        }
        if (! $result->succeeded) {
            $payment->forceFill(['status' => 'failed', 'failure_reason' => $result->failureReason ?? 'Authentication failed.'])->save();
            throw new DomainProblem('payment_declined', $result->failureReason ?? 'Your bank did not approve the payment.', 402, 'Payment declined');
        }

        $meta = (array) $payment->meta;

        return $this->complete($payment, ($meta['save_card'] ?? false) ? (array) ($meta['source'] ?? []) : null, $result);
    }

    /**
     * Gateway-confirmed success (from confirm() or a webhook): apply credits, save the card, issue the invoice
     * and submit the job. Safe to call twice — a payment is completed once.
     *
     * @param  array<string, mixed>|null  $saveCardSource  the card source to save for later, or null
     */
    public function complete(Payment $payment, ?array $saveCardSource = null, ?ChargeResult $result = null): Payment
    {
        [$payment, $submitted] = DB::transaction(function () use ($payment, $saveCardSource, $result) {
            /** @var Payment $payment */
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'succeeded') {
                return [$payment, false];
            }
            /** @var Campaign|PrintOrder|DesignOrder $payable */
            $payable = $payment->payable;
            $business = $payment->business;
            $user = User::find($payment->meta['user_id'] ?? null);
            $credits = (int) $payment->credits_applied_cents;

            $payment->forceFill([
                'status' => 'succeeded',
                'paid_at' => now(),
                'card_brand' => $payment->card_brand ?? $result?->brand,
                'card_last4' => $payment->card_last4 ?? $result?->last4,
            ])->save();

            if ($payable->paid_at !== null) {
                // Paid twice (e.g. a second tab). Keep the money visible so staff can refund it.
                Audit::log('payment.duplicate', $payable, ['payment_id' => $payment->id]);

                return [$payment, false];
            }

            if ($credits > 0) {
                $this->adjustCredits($business, -$credits, 'Applied to '.$payable->reference, $payment, $user);
            }

            if ($saveCardSource && $payment->gateway !== 'credits') {
                $this->saveCard($payment, $saveCardSource, new ChargeResult(true, $payment->gateway_ref, $payment->card_brand, $payment->card_last4), $user);
            }

            $pricing = (array) $payable->pricing;
            Invoice::create([
                'business_id' => $business->id,
                'payment_id' => $payment->id,
                'invoiceable_type' => $payable->getMorphClass(),
                'invoiceable_id' => $payable->id,
                'number' => Reference::invoiceNumber(),
                'lines' => $pricing['lines'] ?? [],
                'billing' => $payment->billing,
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

            Audit::log('payment.succeeded', $payable, ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents, 'credits_cents' => $credits]);

            return [$payment, true];
        });

        if ($submitted) {
            event(new OrderPaid($payment->payable->fresh() ?? $payment->payable, $payment));
        }

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
                $toCard = min($amountCents, $refundable);
                if ($toCard > 0) {
                    // Always back through the gateway that took the payment, even if the active gateway has changed since.
                    $res = $this->gateways->driver($payment->gateway)->refund((string) $payment->gateway_ref, $toCard);
                    if (! $res->succeeded) {
                        throw new DomainProblem('refund_failed', $res->failureReason ?? 'Refund failed.', 502);
                    }
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

    /**
     * @param  array<string, mixed>  $source
     */
    private function saveCard(Payment $payment, array $source, ChargeResult $result, ?User $user): void
    {
        $tok = $this->gateways->driver($payment->gateway)->savedMethod($source, $result);
        SavedCard::create([
            'business_id' => $payment->business_id,
            'gateway' => $payment->gateway,
            'gateway_token' => $tok['token'],
            'brand' => $tok['brand'],
            'last4' => $tok['last4'],
            'exp_month' => $tok['exp_month'],
            'exp_year' => $tok['exp_year'],
            'holder_name' => (string) ($source['name'] ?? $user?->name ?? ''),
            'is_default' => ! SavedCard::where('business_id', $payment->business_id)->where('gateway', $payment->gateway)->exists(),
        ]);
    }

    /**
     * What may be kept on a payment while it waits for authentication: never a card number or CVC.
     *
     * @param  array<string, mixed>|null  $source
     * @return array<string, mixed>
     */
    private static function storableSource(?array $source): array
    {
        return array_intersect_key((array) $source, array_flip(['payment_method', 'token', 'exp_month', 'exp_year', 'name']));
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
