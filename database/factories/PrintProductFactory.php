<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PrintProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintProduct>
 */
class PrintProductFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'category' => 'flyer',
            'size_label' => '148 × 210 mm',
            'width_mm' => 148,
            'height_mm' => 210,
            'bleed_mm' => 3,
            'from_price_cents' => 4,
            'gsm_options' => [115, 150],
            'finish_options' => ['115' => ['gloss'], '150' => ['matt', 'gloss']],
            'quantity_options' => [100, 250, 500, 1000],
            'distributable' => true,
            'distribution_material' => 'a5',
            'turnaround_days' => 3,
            'is_active' => true,
        ];
    }
}
