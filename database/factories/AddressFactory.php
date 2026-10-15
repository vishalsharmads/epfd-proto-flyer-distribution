<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        $lng = fake()->randomFloat(6, 151.0270, 151.0530);
        $lat = fake()->randomFloat(6, -33.8220, -33.8040);
        $num = fake()->numberBetween(1, 250);
        $street = fake()->randomElement(['VICTORIA', 'WILSON', 'PARK', 'CHURCH', 'BRUSH']);
        $type = fake()->randomElement(['ROAD', 'STREET', 'AVENUE']);
        $full = "{$num} {$street} {$type}, RYDALMERE NSW 2116";

        return [
            'gnaf_pid' => 'GANSW'.fake()->unique()->numerify('#########'),
            'state' => 'NSW',
            'locality' => 'RYDALMERE',
            'postcode' => '2116',
            'street_name' => $street,
            'street_type' => $type,
            'street_locality_pid' => 'NSW'.fake()->numerify('######'),
            'number_first' => $num,
            'address_kind' => 'house',
            'geocode_type' => 'PC',
            'confidence' => 2,
            'full_address' => $full,
            'search_text' => strtolower($full),
            'geom' => Geo::point($lng, $lat),
        ];
    }
}
