<?php

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Product;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->randomFloat(2, 10, 900);

        return [
            'type' => ProductType::Product,
            'code' => strtoupper(fake()->unique()->bothify('ART-#####')),
            'name' => ucfirst(fake()->words(3, true)),
            'description' => fake()->optional()->sentence(12),
            'category_id' => null,
            'unit' => UnitOfMeasure::Piece,
            'price' => $price,
            'cost' => fake()->optional(0.7)->randomFloat(2, $price * 0.4, $price * 0.8),
            'vat_rate_id' => fn () => VatRate::default()?->id ?? VatRate::factory(),
            'billing_period' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'type' => ProductType::Service,
            'unit' => UnitOfMeasure::Hour,
            'name' => fake()->randomElement(['Consulenza tecnica', 'Ora di assistenza', 'Sviluppo software', 'Formazione in aula', 'Sopralluogo']),
        ]);
    }

    public function recurring(BillingPeriod $period = BillingPeriod::Monthly): static
    {
        return $this->service()->state(fn () => [
            'unit' => $period === BillingPeriod::Annual ? UnitOfMeasure::Year : UnitOfMeasure::Month,
            'name' => fake()->randomElement(['Canone assistenza', 'Hosting gestito', 'Manutenzione programmata', 'Backup in cloud']),
            'billing_period' => $period,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
