<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Message;
use App\Models\SupportThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'support_thread_id' => SupportThread::factory(),
            'user_id' => null,
            'from_staff' => false,
            'body' => fake()->paragraph(),
        ];
    }
}
