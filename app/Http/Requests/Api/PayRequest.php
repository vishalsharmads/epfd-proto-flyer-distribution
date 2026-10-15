<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout body shared by campaign and print-order payment. Provide either a new `card`
 * (test cards with the mock gateway) or a `saved_card_id`; omit both when credits cover the total.
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
        return [
            'card' => ['nullable', 'array', 'prohibits:saved_card_id'],
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
            'saved_card_id' => $data['saved_card_id'] ?? null,
            'save_card' => (bool) ($data['save_card'] ?? false),
            'use_credits' => (bool) ($data['use_credits'] ?? false),
            'billing' => $data['billing'],
            'idempotency_key' => $this->header('Idempotency-Key') ?: null,
        ];
    }
}
