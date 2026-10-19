<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentGatewayManager;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @tags Webhooks
 */
class WebhookController extends Controller
{
    /**
     * Payment gateway webhook (`mock` or `stripe`).
     *
     * No auth: the signature is verified by the gateway (`X-EPFD-Signature` = hex HMAC-SHA256 of the raw
     * body with the mock secret; Stripe uses `Stripe-Signature`). Idempotent: a repeated event id returns
     * `{"status":"duplicate"}`. Handles `payment.succeeded`, `payment.failed`, `payment.refunded`,
     * `payment_intent.succeeded` and `charge.refunded`; other types are acknowledged and ignored.
     *
     * @unauthenticated
     */
    public function handle(Request $request, string $gateway): JsonResponse
    {
        $driver = $this->gateway($gateway);
        $raw = $request->getContent();
        $sig = $gateway === 'stripe' ? $request->header('Stripe-Signature') : $request->header('X-EPFD-Signature');
        [$type, $ref, $payload] = $driver->parseWebhook($raw, $sig);

        $eventId = (string) ($payload['id'] ?? hash('sha256', $raw));
        $refs = array_values(array_filter([$ref, $payload['data']['object']['payment_intent'] ?? null]));

        try {
            return DB::transaction(function () use ($gateway, $eventId, $type, $ref, $refs, $payload) {
                $eventRowId = DB::table('webhook_events')->insertGetId([
                    'gateway' => $gateway, 'event_id' => $eventId, 'type' => mb_substr($type, 0, 80), 'gateway_ref' => $ref ?: null, 'created_at' => now(),
                ]);
                $payment = $refs ? Payment::whereIn('gateway_ref', $refs)->lockForUpdate()->first() : null;
                $result = $payment ? $this->apply($payment, $type, $payload) : 'ignored';
                DB::table('webhook_events')->where('id', $eventRowId)->update(['payment_id' => $payment?->id, 'result' => $result]);

                return response()->json(['status' => $result, 'event_id' => $eventId, 'payment_id' => $payment?->id]);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate', 'event_id' => $eventId]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function apply(Payment $payment, string $type, array $payload): string
    {
        switch ($type) {
            case 'payment.succeeded':
            case 'payment_intent.succeeded':
                if (in_array($payment->status, ['pending', 'requires_action'], true)) {
                    // The customer may close the tab after 3-D Secure; the webhook then completes the order.
                    $meta = (array) $payment->meta;
                    app(CheckoutService::class)->complete($payment, ($meta['save_card'] ?? false) ? (array) ($meta['source'] ?? []) : null);
                    Audit::log('webhook.payment_succeeded', $payment);

                    return 'processed';
                }

                return 'noop';
            case 'payment.failed':
            case 'payment_intent.payment_failed':
                if (in_array($payment->status, ['pending', 'requires_action'], true)) {
                    $payment->forceFill(['status' => 'failed', 'failure_reason' => 'Reported failed by gateway webhook.'])->save();

                    return 'processed';
                }

                return 'noop';
            case 'payment.refunded':
            case 'charge.refunded':
                $amount = (int) ($payload['data']['amount_cents'] ?? $payload['data']['object']['amount_refunded'] ?? $payment->amount_cents);
                $refunded = max($payment->refunded_cents, min($amount, $payment->amount_cents));
                $payment->forceFill([
                    'refunded_cents' => $refunded,
                    'status' => $refunded >= $payment->amount_cents ? 'refunded' : 'partially_refunded',
                ])->save();
                if ($payment->status === 'refunded') {
                    $payment->invoice?->update(['status' => 'refunded']);
                }
                Audit::log('webhook.payment_refunded', $payment, ['amount_cents' => $refunded]);

                return 'processed';
            default:
                return 'ignored';
        }
    }

    private function gateway(string $name): PaymentGateway
    {
        try {
            return app(PaymentGatewayManager::class)->find($name) ?? abort(404, 'Unknown gateway.');
        } catch (RuntimeException) {
            abort(404, 'Unknown gateway.');
        }
    }
}
