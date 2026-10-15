<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Artwork;
use App\Models\PrintOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artwork>
 */
class ArtworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'print_order_id' => PrintOrder::factory(),
            'side' => 'front',
            'path' => 'artworks/demo/'.fake()->uuid().'.png',
            'original_name' => 'flyer-front.png',
            'mime' => 'image/png',
            'size_bytes' => fake()->numberBetween(20000, 900000),
            'width_px' => 1240,
            'height_px' => 1748,
            'status' => 'pending',
        ];
    }
}
