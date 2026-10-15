<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Suburb;
use App\Models\SuburbStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuburbStat>
 */
class SuburbStatFactory extends Factory
{
    public function definition(): array
    {
        $h = fake()->numberBetween(500, 5000);
        $u = fake()->numberBetween(100, 3000);

        return [
            'suburb_id' => Suburb::factory(),
            'houses' => $h,
            'units' => $u,
            'letterboxes' => $h + $u,
            'computed_at' => now(),
        ];
    }
}
