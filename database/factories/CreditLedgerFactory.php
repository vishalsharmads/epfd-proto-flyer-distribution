<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\CreditLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLedger>
 */
class CreditLedgerFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->numberBetween(500, 5000);

        return [
            'business_id' => Business::factory(),
            'amount_cents' => $amount,
            'balance_after_cents' => $amount,
            'reason' => 'Goodwill credit',
        ];
    }
}
