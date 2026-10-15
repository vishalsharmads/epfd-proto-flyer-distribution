<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\DesignOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DesignOrder>
 */
class DesignOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => null,
            'reference' => 'DSN-'.strtoupper(fake()->unique()->bothify('??##??')),
            'design_type' => 'flyer',
            'package' => 'standard',
            'sides' => 1,
            'status' => 'draft',
            'step' => 2,
            'headline' => 'Grand opening this Saturday',
            'key_message' => '20% off everything all weekend.',
            'call_to_action' => 'Visit us at 12 Main St',
            'contact_details' => '0412 345 678 · hello@example.test',
            'revisions_included' => 3,
            'revisions_used' => 0,
            'subtotal_cents' => 17900,
            'gst_cents' => 1790,
            'total_cents' => 19690,
        ];
    }
}
