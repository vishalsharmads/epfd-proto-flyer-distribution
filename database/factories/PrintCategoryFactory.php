<?php

namespace Database\Factories;

use App\Models\PrintCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrintCategory>
 */
class PrintCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).'s';

        return [
            'slug' => Str::slug($name, '_'),
            'name' => $name,
            'icon' => 'printer',
            'sort' => fake()->numberBetween(10, 100),
            'is_active' => true,
        ];
    }
}
