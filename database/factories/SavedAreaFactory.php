<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\SavedArea;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedArea>
 */
class SavedAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Saved area '.fake()->word(),
            'geom' => Geo::fromGeoJson(SydneyGeo::rectangle(151.040, -33.813)),
            'houses' => fake()->numberBetween(50, 400),
            'units' => fake()->numberBetween(0, 200),
        ];
    }
}
