<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROMO##??')),
            'description' => 'Test promo',
            'type' => 'percent',
            'value' => 10,
            'service' => 'any',
            'is_active' => true,
        ];
    }
}
