<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DesignAsset;
use App\Models\DesignOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DesignAsset>
 */
class DesignAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'design_order_id' => DesignOrder::factory(),
            'kind' => 'proof',
            'version' => 1,
            'path' => 'design/demo/'.fake()->uuid().'.png',
            'original_name' => 'proof-v1.png',
            'mime' => 'image/png',
            'size_bytes' => fake()->numberBetween(20000, 900000),
            'width' => 1240,
            'height' => 1748,
        ];
    }
}
