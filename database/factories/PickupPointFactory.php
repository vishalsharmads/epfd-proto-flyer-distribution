<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PickupPoint;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickupPoint>
 */
class PickupPointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'EPFD '.fake()->unique()->city().' depot',
            'address' => fake()->streetAddress().', Parramatta NSW 2150',
            'state' => 'NSW',
            'geom' => Geo::point(fake()->randomFloat(6, 150.99, 151.03), fake()->randomFloat(6, -33.825, -33.805)),
            'opening_hours' => 'Mon–Fri 8:00am–5:00pm',
            'is_active' => true,
        ];
    }
}
