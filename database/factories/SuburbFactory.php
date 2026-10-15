<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Suburb;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Suburb>
 */
class SuburbFactory extends Factory
{
    public function definition(): array
    {
        $lng = fake()->randomFloat(4, 150.95, 151.10);
        $lat = fake()->randomFloat(4, -33.85, -33.75);

        return [
            'name' => fake()->unique()->city(),
            'state' => 'NSW',
            'postcode' => fake()->numerify('21##'),
            'boundary_source' => 'derived',
            'boundary' => DB::raw('ST_Multi('.Geo::fromGeoJson(SydneyGeo::rectangle($lng, $lat, 0.01, 0.008))->getValue(DB::connection()->getQueryGrammar()).')'),
            'centroid' => Geo::point($lng, $lat),
            'is_serviced' => true,
        ];
    }
}
