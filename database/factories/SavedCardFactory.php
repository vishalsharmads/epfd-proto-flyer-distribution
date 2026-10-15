<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\SavedCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedCard>
 */
class SavedCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'gateway' => 'mock',
            'gateway_token' => 'mock_tok_'.fake()->bothify('####################'),
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) date('Y') + 2,
            'holder_name' => fake()->name(),
            'is_default' => true,
        ];
    }
}
