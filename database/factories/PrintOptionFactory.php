<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PrintOption;
use App\Models\PrintProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintOption>
 */
class PrintOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'print_product_id' => PrintProduct::factory(),
            'gsm' => 150,
            'sides' => 1,
            'quantity' => fake()->unique()->randomElement([100, 250, 500, 1000, 2500, 5000, 10000]),
            'unit_price_hc' => fake()->numberBetween(300, 3000),
        ];
    }
}
