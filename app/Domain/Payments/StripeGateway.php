<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\DomainProblem;
use App\Models\Business;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Stripe via the REST API. The browser collects the card with the Stripe Payment Element and sends a
 * PaymentMethod id; the server creates and confirms a PaymentIntent ("finalize on the server" flow).
 * Cards that need 3-D Secure come back as `requires_action`; the browser runs stripe.handleNextAction()
 * and the server then re-reads the intent. Saved cards are PaymentMethods attached to a Stripe Customer
 * per business (businesses.gateway_customers).
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    /** Webhooks older than this are rejected (replay protection, as Stripe's own libraries do). */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    /** @param array{secret_key?: string|null, publishable_key?: string|null, webhook_secret?: string|null, mode?: string} $credentials */
    public function __construct(private readonly array $credentials) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function charge(int $amountCents, string $currency, array $source, string $idempotencyKey, string $description, ?Business $customer = null, bool $saveForLater = false): ChargeResult
    {
        $paymentMethod = (string) ($source['token'] ?? $source['payment_method'] ?? '');
        if (! str_starts_with($paymentMethod, 'pm_')) {
            return new ChargeResult(false, null, null, null, 'Enter your card details again.');
        }
        $params = [
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'payment_method' => $paymentMethod,
            'confirm' => 'true',
            'description' => $description,
            'payment_method_types[]' => 'card',
            'expand[]' => 'latest_charge',
        ];
        if ($customer && (isset($source['token']) || $saveForLater)) {
            $params['customer'] = $this->customerId($customer);
        }
        if ($saveForLater && ! isset($source['token'])) {
            $params['setup_future_usage'] = 'off_session';
        }

        try {
            $res = $this->client()->withHeaders(['Idempotency-Key' => $idempotencyKey])->asForm()->post(self::API.'/payment_intents', $params);
        } catch (ConnectionException) {
            return new ChargeResult(false, null, null, null, 'We could not reach the payment provider. Please try again.');
        }

        return $this->intentResult($res);
    }

    public function retrieve(string $gatewayRef): ChargeResult
    {
        try {
            return $this->intentResult($this->client()->get(self::API.'/payment_intents/'.urlencode($gatewayRef), ['expand' => ['latest_charge']]));
        } catch (ConnectionException) {
            return new ChargeResult(false, $gatewayRef, null, null, 'We could not reach the payment provider. Please try again.');
        }
    }

    public function refund(string $gatewayRef, int $amountCents): ChargeResult
    {
        $res = $this->client()->withHeaders(['Idempotency-Key' => 'refund_'.Str::uuid()])->asForm()->post(self::API.'/refunds', ['payment_intent' => $gatewayRef, 'amount' => $amountCents]);

        return new ChargeResult($res->successful(), $res->json('id'), null, null, $res->json('error.message'));
    }

    public function savedMethod(array $source, ChargeResult $charge): array
    {
        $paymentMethod = (string) ($source['payment_method'] ?? '');
        $card = (array) $this->client()->get(self::API.'/payment_methods/'.urlencode($paymentMethod))->throw()->json('card');

        return [
            'token' => $paymentMethod,
            'brand' => (string) ($card['brand'] ?? $charge->brand ?? 'card'),
            'last4' => (string) ($card['last4'] ?? $charge->last4 ?? ''),
            'exp_month' => (int) ($card['exp_month'] ?? 12),
            'exp_year' => (int) ($card['exp_year'] ?? (int) date('Y') + 2),
        ];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        $secret = (string) ($this->credentials['webhook_secret'] ?? '');
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $signature) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = ctype_digit($v) ? (int) $v : null;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        $expected = $timestamp !== null ? hash_hmac('sha256', $timestamp.'.'.$payload, $secret) : '';
        $valid = $secret !== '' && $timestamp !== null && abs(time() - $timestamp) <= self::WEBHOOK_TOLERANCE_SECONDS
            && array_filter($signatures, fn (string $sig) => hash_equals($expected, $sig)) !== [];
        if (! $valid) {
            throw new DomainProblem('webhook_signature_invalid', 'Invalid Stripe signature.', 400, 'Bad webhook');
        }
        $data = (array) json_decode($payload, true);

        return [(string) ($data['type'] ?? ''), (string) ($data['data']['object']['id'] ?? ''), $data];
    }

    public function clientConfig(): array
    {
        return ['driver' => 'stripe', 'publishable_key' => (string) ($this->credentials['publishable_key'] ?? '')];
    }

    public function verifyCredentials(): ?string
    {
        try {
            $res = $this->client()->get(self::API.'/balance');
        } catch (ConnectionException) {
            return 'Could not reach Stripe.';
        }
        if (! $res->successful()) {
            return (string) ($res->json('error.message') ?? 'Stripe rejected the secret key.');
        }
        $live = str_starts_with((string) ($this->credentials['secret_key'] ?? ''), 'sk_live_');
        if ($live !== (($this->credentials['mode'] ?? 'test') === 'live')) {
            return $live ? 'This is a live key but the gateway is in test mode.' : 'This is a test key but the gateway is in live mode.';
        }

        return null;
    }

    /** The Stripe Customer for a business, created on first use. Kept per mode: test and live customers differ. */
    private function customerId(Business $business): string
    {
        $key = 'stripe_'.($this->credentials['mode'] ?? 'test');
        $customers = (array) $business->gateway_customers;
        if (! empty($customers[$key])) {
            return (string) $customers[$key];
        }
        $id = (string) $this->client()->withHeaders(['Idempotency-Key' => 'customer_'.$business->id.'_'.$key])->asForm()->post(self::API.'/customers', [
            'name' => $business->name,
            'email' => $business->email,
            'metadata[business_id]' => $business->id,
        ])->throw()->json('id');
        $business->forceFill(['gateway_customers' => [...$customers, $key => $id]])->save();

        return $id;
    }

    private function intentResult(Response $res): ChargeResult
    {
        $intent = (array) ($res->json('error.payment_intent') ?? $res->json());
        $card = (array) ($intent['latest_charge']['payment_method_details']['card'] ?? []);
        $brand = $card['brand'] ?? null;
        $last4 = $card['last4'] ?? null;

        return match ($res->successful() ? ($intent['status'] ?? '') : 'failed') {
            'succeeded' => new ChargeResult(true, $intent['id'] ?? null, $brand, $last4),
            'requires_action' => ChargeResult::actionRequired((string) $intent['id'], (string) ($intent['client_secret'] ?? ''), $brand, $last4),
            default => new ChargeResult(false, $intent['id'] ?? null, $brand, $last4,
                $res->json('error.message') ?? ($intent['last_payment_error']['message'] ?? 'The payment was not completed.')),
        };
    }

    private function client(): PendingRequest
    {
        // Only connection failures are retried; every POST carries an Idempotency-Key, so a retry never charges twice.
        return Http::withToken((string) ($this->credentials['secret_key'] ?? ''))->timeout(20)
            ->retry(2, 250, fn ($e) => $e instanceof ConnectionException, throw: false);
    }
}
