<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Run;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'reference' => 'RUN-'.strtoupper(fake()->unique()->bothify('??##??')),
            'name' => 'Run 1',
            'geom' => Geo::fromGeoJson(SydneyGeo::rectangle(151.040, -33.813)),
            'flyers' => 300,
            'houses' => 250,
            'units' => 50,
            'distance_km' => 4.2,
            'pay_cents' => 9600,
            'deadline' => now()->addWeek()->toDateString(),
            'status' => 'draft',
        ];
    }
}
