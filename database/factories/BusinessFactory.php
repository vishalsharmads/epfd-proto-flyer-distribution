<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $lng = fake()->randomFloat(6, 151.0000, 151.0600);
        $lat = fake()->randomFloat(6, -33.8250, -33.7900);

        return [
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'mobile' => '04'.fake()->numerify('## ### ###'),
            'abn' => fake()->numerify('###########'),
            'industry' => fake()->randomElement(['Hospitality', 'Health', 'Retail', 'Trades', 'Real estate']),
            'address' => fake()->buildingNumber().' Victoria Road, Rydalmere NSW 2116',
            'state' => 'NSW',
            'geom' => Geo::point($lng, $lat),
            'status' => 'approved',
            'approved_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'approved_at' => null]);
    }
}
