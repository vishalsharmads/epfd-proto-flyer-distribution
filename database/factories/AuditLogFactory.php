<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => fake()->randomElement(['campaign.status_changed', 'payment.succeeded', 'run.assigned']),
            'changes' => ['note' => fake()->sentence()],
            'ip' => fake()->ipv4(),
            'user_agent' => 'Seeder',
        ];
    }
}
