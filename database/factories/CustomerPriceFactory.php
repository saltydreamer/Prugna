<?php

namespace Database\Factories;

use App\Models\CustomerPrice;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPrice>
 */
class CustomerPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'product_id' => Product::factory(),
            'price' => null,
            'discount_percent' => 10,
            'valid_until' => null,
            'notes' => null,
        ];
    }
}
