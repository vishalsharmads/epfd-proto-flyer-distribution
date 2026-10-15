<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\DomainProblem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Optional Stripe (test mode) gateway via the REST API — enable with PAYMENT_GATEWAY=stripe and
 * STRIPE_SECRET=sk_test_…. In production the browser should tokenise with Stripe Elements; the
 * prototype accepts Stripe test PaymentMethod ids (pm_card_visa, pm_card_chargeDeclined) in place of
 * a card number so raw PANs never reach this server.
 */
final class StripeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function charge(int $amountCents, string $currency, array $card, string $idempotencyKey, string $description): ChargeResult
    {
        $pm = (string) ($card['token'] ?? $card['payment_method'] ?? 'pm_card_visa');
        $res = $this->client()->withHeaders(['Idempotency-Key' => $idempotencyKey])->asForm()->post('https://api.stripe.com/v1/payment_intents', [
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'payment_method' => $pm,
            'confirm' => 'true',
            'description' => $description,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        ]);
        $j = $res->json();
        if (! $res->successful() || ($j['status'] ?? '') !== 'succeeded') {
            return new ChargeResult(false, $j['error']['payment_intent']['id'] ?? null, null, null, $j['error']['message'] ?? 'Payment failed.');
        }

        return new ChargeResult(true, $j['id'], $j['payment_method_details']['card']['brand'] ?? null, $j['payment_method_details']['card']['last4'] ?? null);
    }

    public function refund(string $gatewayRef, int $amountCents): ChargeResult
    {
        $res = $this->client()->asForm()->post('https://api.stripe.com/v1/refunds', ['payment_intent' => $gatewayRef, 'amount' => $amountCents]);

        return new ChargeResult($res->successful(), $res->json('id'), null, null, $res->json('error.message'));
    }

    public function tokenize(array $card): array
    {
        return ['token' => (string) ($card['payment_method'] ?? 'pm_card_visa'), 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => (int) date('Y') + 2];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        $secret = (string) config('epfd.payments.stripe_webhook_secret');
        if (! $signature || ! preg_match('/t=(\d+),.*v1=([a-f0-9]+)/', $signature, $m)
            || ! hash_equals(hash_hmac('sha256', $m[1].'.'.$payload, $secret), $m[2])) {
            throw new DomainProblem('webhook_signature_invalid', 'Invalid Stripe signature.', 400, 'Bad webhook');
        }
        $data = json_decode($payload, true);

        return [(string) ($data['type'] ?? ''), (string) ($data['data']['object']['id'] ?? ''), (array) $data];
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('epfd.payments.stripe_secret'))->timeout(20);
    }
}
