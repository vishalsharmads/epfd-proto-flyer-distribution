<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * Outcome of a gateway charge. A charge either succeeded, failed, or needs the customer to
 * authenticate in the browser first (3-D Secure): then `requiresAction` is true and the browser
 * completes it with `clientSecret` before the server confirms it.
 */
final readonly class ChargeResult
{
    public function __construct(
        public bool $succeeded,
        public ?string $gatewayRef,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?string $failureReason = null,
        public bool $requiresAction = false,
        public ?string $clientSecret = null,
    ) {}

    public static function actionRequired(string $gatewayRef, string $clientSecret, ?string $brand = null, ?string $last4 = null): self
    {
        return new self(false, $gatewayRef, $brand, $last4, null, true, $clientSecret);
    }
}
