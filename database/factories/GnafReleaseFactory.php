<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GnafRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GnafRelease>
 */
class GnafReleaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label' => 'AUG 2026',
            'states' => ['NSW', 'VIC'],
            'is_sample' => true,
            'status' => 'completed',
            'row_counts' => [],
            'started_at' => now()->subMinutes(5),
            'imported_at' => now(),
        ];
    }
}
