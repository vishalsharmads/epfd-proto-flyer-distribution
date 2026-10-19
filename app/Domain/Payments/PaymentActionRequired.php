<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\DomainProblem;
use App\Models\Payment;

/**
 * The card needs customer authentication (3-D Secure). The browser completes it with the gateway SDK
 * using `client_secret`, then confirms the payment at `confirm_url`.
 */
final class PaymentActionRequired extends DomainProblem
{
    public function __construct(public readonly Payment $payment, string $clientSecret)
    {
        parent::__construct('payment_action_required', 'Your bank needs you to confirm this payment.', 402, 'Authentication required', [
            'payment_id' => $payment->id,
            'gateway' => $payment->gateway,
            'client_secret' => $clientSecret,
            'confirm_url' => route('api.payments.confirm', $payment),
            'web_confirm_url' => route('app.payments.confirm', $payment),
        ]);
    }
}
