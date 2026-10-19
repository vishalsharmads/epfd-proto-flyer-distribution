<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Business;

/**
 * A payment gateway driver (see PaymentGatewayManager). Card details are collected by the gateway's
 * browser SDK, so the server only ever sees a payment method id or a saved-card token.
 *
 * `$source` is one of:
 *   ['payment_method' => 'pm_…']  a new card tokenised in the browser
 *   ['token' => '…']              a saved card
 *   raw test card fields          the mock gateway only (never in production)
 */
interface PaymentGateway
{
    public function name(): string;

    /** @param array<string, mixed> $source */
    public function charge(int $amountCents, string $currency, array $source, string $idempotencyKey, string $description, ?Business $customer = null, bool $saveForLater = false): ChargeResult;

    /** Re-read a charge after the customer finished authentication in the browser. */
    public function retrieve(string $gatewayRef): ChargeResult;

    public function refund(string $gatewayRef, int $amountCents): ChargeResult;

    /**
     * Details of the card used for a successful charge, to store as a saved card. Never includes the card number.
     *
     * @param  array<string, mixed>  $source
     * @return array{token:string, brand:string, last4:string, exp_month:int, exp_year:int}
     */
    public function savedMethod(array $source, ChargeResult $charge): array;

    /**
     * Verify + parse a webhook.
     *
     * @return array{0:string, 1:string, 2:array<string, mixed>} [eventType, gatewayRef, payload]
     */
    public function parseWebhook(string $payload, ?string $signature): array;

    /**
     * Public settings the browser needs to collect a card (never secrets).
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array;

    /** Check the credentials with a harmless API call. Returns an error message, or null when they work. */
    public function verifyCredentials(): ?string;
}
