<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\SupportThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportThread>
 */
class SupportThreadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'subject' => fake()->sentence(5),
            'status' => 'open',
            'priority' => 'normal',
            'channel' => 'support',
            'last_message_at' => now(),
        ];
    }
}
