<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout body shared by campaign, print and design payment. Provide a `payment_method` id from the
 * gateway's SDK (Stripe: `pm_…`), a `saved_card_id`, or — with the mock gateway only — a raw test `card`.
 * Omit them all when credits cover the total. A 402 `payment_action_required` response means the card needs
 * 3-D Secure: complete it with `client_secret`, then POST `confirm_url`.
 */
class PayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $mock = app(PaymentGatewayManager::class)->getDefaultDriver() === 'mock';

        return [
            /** @example pm_card_visa */
            'payment_method' => ['nullable', 'string', 'max:255', 'regex:/^pm_[A-Za-z0-9_]+$/', 'prohibits:saved_card_id,card'],
            'card' => $mock ? ['nullable', 'array', 'prohibits:saved_card_id'] : ['prohibited'],
            /** @example 4242424242424242 */
            'card.number' => ['required_with:card', 'string', 'regex:/^[\d ]{12,23}$/'],
            'card.exp_month' => ['required_with:card', 'integer', 'between:1,12'],
            'card.exp_year' => ['required_with:card', 'integer', 'min:'.date('Y')],
            'card.cvc' => ['required_with:card', 'string', 'regex:/^\d{3,4}$/'],
            'card.name' => ['required_with:card', 'string', 'max:120'],
            'saved_card_id' => ['nullable', 'integer'],
            'save_card' => ['boolean'],
            'use_credits' => ['boolean'],
            'billing' => ['required', 'array'],
            'billing.company' => ['required', 'string', 'max:120'],
            'billing.abn' => ['nullable', 'string', 'max:14'],
            'billing.address' => ['required', 'string', 'max:255'],
            'accept_terms' => ['accepted'],
        ];
    }

    /** @return array<string, mixed> */
    public function checkoutInput(): array
    {
        $data = $this->validated();

        return [
            'card' => $data['card'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'saved_card_id' => $data['saved_card_id'] ?? null,
            'save_card' => (bool) ($data['save_card'] ?? false),
            'use_credits' => (bool) ($data['use_credits'] ?? false),
            'billing' => $data['billing'],
            'idempotency_key' => $this->header('Idempotency-Key') ?: null,
        ];
    }
}
