<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintOrder>
 */
class PrintOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => null,
            'reference' => 'PRT-'.strtoupper(fake()->unique()->bothify('??##??')),
            'print_product_id' => PrintProduct::factory(),
            'status' => 'draft',
            'step' => 2,
            'gsm' => 150,
            'finish' => 'matt',
            'sides' => 1,
            'quantity' => 1000,
            'delivery_name' => fake()->name(),
            'delivery_address' => fake()->streetAddress().', Rydalmere NSW 2116',
            'delivery_phone' => '0412 345 678',
        ];
    }
}
