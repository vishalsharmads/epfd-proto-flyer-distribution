<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pricing\RateCardDefaults;
use App\Models\RateCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateCard>
 */
class RateCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Rate card '.fake()->word(),
            'is_active' => false,
            'data' => RateCardDefaults::data(),
        ];
    }
}
