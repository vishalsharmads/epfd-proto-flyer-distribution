<?php

declare(strict_types=1);

namespace App\Http\Requests\App;

use App\Domain\Payments\PaymentGatewayManager;
use App\Rules\Abn;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared checkout payload for campaigns, print and design orders. A new card arrives as a gateway
 * `payment_method` id tokenised in the browser; raw card fields are only accepted by the mock gateway.
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
        $newCard = $mock ? 'required_without_all:saved_card_id,payment_method' : 'prohibited';

        return [
            'payment_method' => ['nullable', 'string', 'max:255', 'regex:/^pm_[A-Za-z0-9_]+$/'],
            'billing.company' => ['required', 'string', 'max:160'],
            'billing.abn' => ['nullable', new Abn],
            'billing.address' => ['required', 'string', 'max:255'],
            'saved_card_id' => ['nullable', 'integer'],
            'card.number' => ['nullable', $newCard, 'string', 'max:23'],
            'card.exp' => ['nullable', $newCard, 'regex:/^(0[1-9]|1[0-2])\s*\/\s*\d{2}$/'],
            'card.cvc' => ['nullable', $newCard, 'digits_between:3,4'],
            'card.name' => ['nullable', $newCard, 'string', 'max:120'],
            'card.country' => ['nullable', 'in:AU,NZ,US,UK,CA,IE,SG'],
            'save_card' => ['boolean'],
            'use_credits' => ['boolean'],
            /** The total the customer was shown (cents). When it no longer matches, nothing is charged (409 `price_changed`). */
            'expected_total_cents' => ['nullable', 'integer', 'min:0'],
            'accept_terms' => ['accepted'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            // print-only acknowledgements (validated by the print controller)
            'artwork_checked' => ['sometimes', 'boolean'],
            'right_to_print' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Please accept the Terms & Conditions, Community Guidelines and Privacy Policy.',
            'card.exp.regex' => 'Enter the expiry as MM/YY.',
            'card.number.required_without_all' => 'Enter a card number or choose a saved card.',
            'card.*.prohibited' => 'Card details must be entered in the secure card form.',
            'billing.company.required' => 'Enter the company name for the invoice.',
            'billing.address.required' => 'Enter the billing address.',
        ];
    }

    /** Map to the CheckoutService::pay input shape. */
    public function checkoutInput(): array
    {
        $d = $this->validated();
        $card = null;
        if (empty($d['saved_card_id']) && ! empty($d['card']['number'])) {
            [$m, $y] = array_map('trim', explode('/', (string) $d['card']['exp']));
            $card = [
                'number' => preg_replace('/\D/', '', (string) $d['card']['number']),
                'exp_month' => (int) $m,
                'exp_year' => 2000 + (int) $y,
                'cvc' => (string) $d['card']['cvc'],
                'name' => (string) $d['card']['name'],
                'country' => $d['card']['country'] ?? 'AU',
            ];
        }

        return [
            'card' => $card,
            'payment_method' => $d['payment_method'] ?? null,
            'saved_card_id' => $d['saved_card_id'] ?? null,
            'save_card' => (bool) ($d['save_card'] ?? false),
            'use_credits' => (bool) ($d['use_credits'] ?? false),
            'expected_total_cents' => isset($d['expected_total_cents']) ? (int) $d['expected_total_cents'] : null,
            'billing' => ['company' => $d['billing']['company'], 'abn' => Abn::normalise($d['billing']['abn'] ?? null), 'address' => $d['billing']['address']],
            'idempotency_key' => $d['idempotency_key'] ?? null,
        ];
    }
}
