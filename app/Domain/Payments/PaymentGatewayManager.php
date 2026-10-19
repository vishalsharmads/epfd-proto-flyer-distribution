<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\PaymentGatewayConfig;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves payment gateway drivers by name. The active driver is the `payments.gateway` setting
 * (admin → Settings), defaulting to PAYMENT_GATEWAY.
 *
 * Credentials come from the admin Payment gateways page (encrypted in payment_gateways), falling back to env.
 * To add a gateway: write a PaymentGateway class, a create{Name}Driver method, and list it in DRIVERS.
 *
 * The mock gateway accepts any Luhn-valid card, so it is refused in production, and a production
 * gateway with missing credentials fails loudly instead of quietly falling back to mock.
 */
final class PaymentGatewayManager extends Manager
{
    /**
     * @var array<string, array{label: string, fields: array<string, array{label: string, secret: bool}>}>
     */
    public const DRIVERS = [
        'mock' => [
            'label' => 'Test gateway (no real money)',
            'fields' => ['webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true]],
        ],
        'stripe' => [
            'label' => 'Stripe',
            'fields' => [
                'publishable_key' => ['label' => 'Publishable key (pk_…)', 'secret' => false],
                'secret_key' => ['label' => 'Secret key (sk_…)', 'secret' => true],
                'webhook_secret' => ['label' => 'Webhook signing secret (whsec_…)', 'secret' => true],
            ],
        ],
    ];

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('epfd.payments.gateway', 'mock');
    }

    /**
     * Gateways an admin may choose from in this environment.
     *
     * @return array<string, string> driver => label
     */
    public function available(): array
    {
        $drivers = array_map(fn (array $d) => $d['label'], self::DRIVERS);

        return $this->container->isProduction() ? array_diff_key($drivers, ['mock' => true]) : $drivers;
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

    /**
     * Effective credentials for a driver: admin-saved values override env defaults.
     *
     * @return array<string, string|null>
     */
    public function credentials(string $driver): array
    {
        $env = match ($driver) {
            'stripe' => [
                'publishable_key' => $this->config->get('epfd.payments.stripe_key'),
                'secret_key' => $this->config->get('epfd.payments.stripe_secret'),
                'webhook_secret' => $this->config->get('epfd.payments.stripe_webhook_secret'),
            ],
            'mock' => ['webhook_secret' => $this->config->get('epfd.payments.mock_webhook_secret')],
            default => [],
        };
        $saved = PaymentGatewayConfig::find($driver);

        return [
            ...$env,
            ...array_filter((array) $saved?->credentials, fn ($v) => $v !== null && $v !== ''),
            'mode' => $saved->mode ?? (str_starts_with((string) ($env['secret_key'] ?? ''), 'sk_live_') ? 'live' : 'test'),
        ];
    }

    /** Drop resolved drivers so the next call picks up newly saved credentials or settings. */
    public function refresh(): void
    {
        $this->forgetDrivers();
        $this->container->forgetInstance(PaymentGateway::class);
    }

    protected function createMockDriver(): PaymentGateway
    {
        if ($this->container->isProduction()) {
            throw new RuntimeException('The mock payment gateway cannot be used in production. Choose a real gateway.');
        }

        return new MockGateway($this->credentials('mock'));
    }

    protected function createStripeDriver(): PaymentGateway
    {
        $credentials = $this->credentials('stripe');
        if (! $credentials['secret_key'] || ! $credentials['publishable_key'] || ! $credentials['webhook_secret']) {
            if ($this->container->isProduction()) {
                throw new RuntimeException('The Stripe gateway needs a publishable key, secret key and webhook secret.');
            }

            return new MockGateway($this->credentials('mock'));
        }

        return new StripeGateway($credentials);
    }
}
