<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Campaign;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => null,
            'reference' => 'CMP-'.strtoupper(fake()->unique()->bothify('??##??')),
            'name' => 'Campaign '.fake()->unique()->words(3, true),
            'status' => 'draft',
            'step' => 1,
            'pickup_address' => '257 Victoria Road, Rydalmere NSW 2116',
            'pickup_geom' => Geo::point(151.0365, -33.8126),
            'available_from' => now()->toDateString(),
        ];
    }
}
