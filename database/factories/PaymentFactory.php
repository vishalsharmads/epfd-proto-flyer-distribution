<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'payable_type' => (new Campaign)->getMorphClass(),
            'payable_id' => Campaign::factory(),
            'gateway' => 'mock',
            'gateway_ref' => 'mock_ch_'.fake()->bothify('################'),
            'amount_cents' => fake()->numberBetween(10000, 100000),
            'currency' => 'AUD',
            'status' => 'succeeded',
            'card_brand' => 'visa',
            'card_last4' => '4242',
            'paid_at' => now(),
        ];
    }
}
