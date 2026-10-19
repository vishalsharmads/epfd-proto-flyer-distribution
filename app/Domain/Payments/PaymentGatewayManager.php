<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves payment gateway drivers by name. The default driver is epfd.payments.gateway.
 * Add a gateway by writing a PaymentGateway class and a create{Name}Driver method (or extend()).
 *
 * The mock gateway accepts any Luhn-valid card, so it is refused in production, and a production
 * gateway with missing credentials fails loudly instead of quietly falling back to mock.
 */
final class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('epfd.payments.gateway', 'mock');
    }

    /**
     * Resolve a driver by name, turning an unknown name into null instead of an exception.
     */
    public function find(string $name): ?PaymentGateway
    {
        try {
            return $this->driver($name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function createMockDriver(): PaymentGateway
    {
        if ($this->container->isProduction()) {
            throw new RuntimeException('The mock payment gateway cannot be used in production. Set PAYMENT_GATEWAY to a real gateway.');
        }

        return new MockGateway;
    }

    protected function createStripeDriver(): PaymentGateway
    {
        if (! $this->config->get('epfd.payments.stripe_secret') || ! $this->config->get('epfd.payments.stripe_webhook_secret')) {
            if ($this->container->isProduction()) {
                throw new RuntimeException('The Stripe gateway needs STRIPE_SECRET and STRIPE_WEBHOOK_SECRET.');
            }

            return new MockGateway;
        }

        return new StripeGateway;
    }
}
