<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\DomainProblem;
use App\Models\Business;
use Illuminate\Support\Str;

/**
 * Offline test gateway for local development and tests; refused in production by PaymentGatewayManager.
 * Any Luhn-valid card succeeds instantly, except:
 *   - the decline card (epfd.payments.mock_decline_card, 4000 0000 0000 0002) always fails
 *   - the authentication card (4000 0025 0000 3155) needs a simulated 3-D Secure confirmation first
 * Webhooks are signed with HMAC-SHA256 using the configured webhook secret.
 */
final class MockGateway implements PaymentGateway
{
    public const AUTH_CARD = '4000002500003155';

    /** @param array{webhook_secret?: string|null} $credentials */
    public function __construct(private readonly array $credentials = []) {}

    public function name(): string
    {
        return 'mock';
    }

    public function charge(int $amountCents, string $currency, array $source, string $idempotencyKey, string $description, ?Business $customer = null, bool $saveForLater = false): ChargeResult
    {
        if (isset($source['token'])) {
            $declined = str_contains((string) $source['token'], 'decline');

            return new ChargeResult(! $declined, 'mock_ch_'.Str::random(16), null, null, $declined ? 'Your card was declined.' : null);
        }

        $number = preg_replace('/\D/', '', (string) ($source['number'] ?? ''));
        if (! self::luhn($number)) {
            return new ChargeResult(false, null, null, null, 'The card number is invalid.');
        }
        $brand = self::brand($number);
        if ($number === preg_replace('/\D/', '', (string) config('epfd.payments.mock_decline_card'))) {
            return new ChargeResult(false, 'mock_ch_'.Str::random(16), $brand, substr($number, -4), 'Your card was declined.');
        }
        if ($number === self::AUTH_CARD) {
            return ChargeResult::actionRequired('mock_3ds_'.Str::random(16), 'mock_cs_'.Str::random(24), $brand, substr($number, -4));
        }

        return new ChargeResult(true, 'mock_ch_'.Str::random(16), $brand, substr($number, -4));
    }

    public function retrieve(string $gatewayRef): ChargeResult
    {
        return new ChargeResult(str_starts_with($gatewayRef, 'mock_'), $gatewayRef, null, null, str_starts_with($gatewayRef, 'mock_') ? null : 'Unknown payment.');
    }

    public function refund(string $gatewayRef, int $amountCents): ChargeResult
    {
        return new ChargeResult(true, 'mock_re_'.Str::random(16));
    }

    public function cancel(string $gatewayRef): bool
    {
        return str_starts_with($gatewayRef, 'mock_');
    }

    public function savedMethod(array $source, ChargeResult $charge): array
    {
        return [
            'token' => 'mock_tok_'.Str::random(20),
            'brand' => (string) ($charge->brand ?? 'card'),
            'last4' => (string) ($charge->last4 ?? '0000'),
            'exp_month' => (int) ($source['exp_month'] ?? 12),
            'exp_year' => (int) ($source['exp_year'] ?? (int) date('Y') + 2),
        ];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        $secret = (string) ($this->credentials['webhook_secret'] ?? '');
        if ($secret === '' || ! $signature || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            throw new DomainProblem('webhook_signature_invalid', 'Invalid webhook signature.', 400, 'Bad webhook');
        }
        $data = json_decode($payload, true);

        return [(string) ($data['type'] ?? ''), (string) ($data['data']['gateway_ref'] ?? ''), (array) $data];
    }

    public function clientConfig(): array
    {
        return [
            'driver' => 'mock',
            'test_card' => '4242 4242 4242 4242',
            'auth_card' => '4000 0025 0000 3155',
            'decline_card' => trim(chunk_split((string) config('epfd.payments.mock_decline_card'), 4, ' ')),
        ];
    }

    public function verifyCredentials(): ?string
    {
        return null;
    }

    public static function luhn(string $number): bool
    {
        if (! preg_match('/^\d{12,19}$/', $number)) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int) $number[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    public static function brand(string $number): string
    {
        return match (true) {
            str_starts_with($number, '4') => 'visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $number) => 'mastercard',
            (bool) preg_match('/^3[47]/', $number) => 'amex',
            default => 'card',
        };
    }
}
