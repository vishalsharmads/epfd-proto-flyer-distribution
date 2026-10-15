<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Run;
use App\Models\RunGpsPoint;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunGpsPoint>
 */
class RunGpsPointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'geom' => Geo::point(fake()->randomFloat(6, 151.037, 151.043), fake()->randomFloat(6, -33.815, -33.811)),
            'accuracy_m' => 5,
            'speed_mps' => 1.25,
            'recorded_at' => now(),
        ];
    }
}
