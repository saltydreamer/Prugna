<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Hardware', 'Software', 'Consulenza', 'Assistenza', 'Formazione', 'Ricambi', 'Licenze', 'Installazioni']),
            'color' => fake()->randomElement(ProductCategory::COLORS),
            'description' => null,
        ];
    }
}
