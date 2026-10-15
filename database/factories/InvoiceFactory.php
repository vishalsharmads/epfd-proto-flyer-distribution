<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $sub = fake()->numberBetween(10000, 100000);
        $gst = (int) round($sub * 0.1);

        return [
            'business_id' => Business::factory(),
            'invoiceable_type' => (new Campaign)->getMorphClass(),
            'invoiceable_id' => Campaign::factory(),
            'number' => 'EPFD-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'lines' => [['code' => 'distribution', 'label' => 'Letterbox distribution', 'quantity' => 1, 'unit_cents' => $sub, 'amount_cents' => $sub]],
            'billing' => ['company' => fake()->company(), 'abn' => null, 'address' => fake()->address()],
            'subtotal_cents' => $sub,
            'gst_cents' => $gst,
            'total_cents' => $sub + $gst,
            'status' => 'paid',
            'issued_at' => now(),
        ];
    }
}
