<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignArea>
 */
class CampaignAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'name' => 'Run area 1',
            'position' => 1,
            'draw_mode' => 'pins',
            'geom' => Geo::fromGeoJson(SydneyGeo::rectangle(151.040, -33.813)),
            'polygon_hash' => hash('sha256', fake()->uuid()),
            'houses' => fake()->numberBetween(50, 400),
            'units' => fake()->numberBetween(0, 200),
            'distance_km' => fake()->randomFloat(2, 1, 8),
            'fee_cents' => fake()->numberBetween(5000, 50000),
        ];
    }
}
