<?php

declare(strict_types=1);

namespace App\Domain\Payments;

interface PaymentGateway
{
    public function name(): string;

    /**
     * Charge a card. `$card` is either a raw test card (number/exp/cvc/name) for a new card or
     * ['token' => ...] for a saved card. Never log or persist the raw number.
     *
     * @param  array<string, mixed>  $card
     */
    public function charge(int $amountCents, string $currency, array $card, string $idempotencyKey, string $description): ChargeResult;

    public function refund(string $gatewayRef, int $amountCents): ChargeResult;

    /**
     * Tokenise a card for later use (saved cards).
     *
     * @param  array<string, mixed>  $card
     * @return array{token:string, brand:string, last4:string, exp_month:int, exp_year:int}
     */
    public function tokenize(array $card): array;

    /** Verify + parse a webhook. Returns [eventType, gatewayRef, payload] or throws. */
    public function parseWebhook(string $payload, ?string $signature): array;
}
