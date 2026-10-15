<?php

declare(strict_types=1);

namespace App\Domain\Payments;

final readonly class ChargeResult
{
    public function __construct(
        public bool $succeeded,
        public ?string $gatewayRef,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?string $failureReason = null,
    ) {}
}
