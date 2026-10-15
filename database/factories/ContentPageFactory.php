<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentPage>
 */
class ContentPageFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'type' => 'faq',
            'slug' => Str::slug($title),
            'title' => $title,
            'body' => fake()->paragraphs(2, true),
            'sort' => 0,
            'is_published' => true,
        ];
    }
}
