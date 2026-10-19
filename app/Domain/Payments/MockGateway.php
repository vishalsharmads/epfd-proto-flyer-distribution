<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\DomainProblem;
use Illuminate\Support\Str;

/**
 * Offline test gateway. Any Luhn-valid card succeeds instantly, except the magic decline card
 * (config epfd.payments.mock_decline_card, default 4000 0000 0000 0002) which always fails.
 * Webhooks are signed with HMAC-SHA256 using epfd.payments.mock_webhook_secret.
 */
final class MockGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function charge(int $amountCents, string $currency, array $card, string $idempotencyKey, string $description): ChargeResult
    {
        if (isset($card['token'])) {
            $token = (string) $card['token'];
            $declined = str_contains($token, 'decline');

            return new ChargeResult(! $declined, 'mock_ch_'.Str::random(16), null, null, $declined ? 'Your card was declined.' : null);
        }

        $number = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        if (! self::luhn($number)) {
            return new ChargeResult(false, null, null, null, 'The card number is invalid.');
        }
        $brand = self::brand($number);
        if ($number === preg_replace('/\D/', '', (string) config('epfd.payments.mock_decline_card'))) {
            return new ChargeResult(false, 'mock_ch_'.Str::random(16), $brand, substr($number, -4), 'Your card was declined.');
        }

        return new ChargeResult(true, 'mock_ch_'.Str::random(16), $brand, substr($number, -4));
    }

    public function refund(string $gatewayRef, int $amountCents): ChargeResult
    {
        return new ChargeResult(true, 'mock_re_'.Str::random(16));
    }

    public function tokenize(array $card): array
    {
        $number = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        $declines = $number === preg_replace('/\D/', '', (string) config('epfd.payments.mock_decline_card'));

        return [
            'token' => 'mock_tok_'.($declines ? 'decline_' : '').Str::random(20),
            'brand' => self::brand($number),
            'last4' => substr($number, -4),
            'exp_month' => (int) ($card['exp_month'] ?? 12),
            'exp_year' => (int) ($card['exp_year'] ?? (int) date('Y') + 2),
        ];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        $secret = (string) config('epfd.payments.mock_webhook_secret');
        if ($secret === '' || ! $signature || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            throw new DomainProblem('webhook_signature_invalid', 'Invalid webhook signature.', 400, 'Bad webhook');
        }
        $data = json_decode($payload, true);

        return [(string) ($data['type'] ?? ''), (string) ($data['data']['gateway_ref'] ?? ''), (array) $data];
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
