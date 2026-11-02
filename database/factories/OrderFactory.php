<?php

namespace Database\Factories;

use App\Actions\Orders\SaveOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * After creation the order goes through SaveOrder, so it has customer data, lines and totals.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => today()->year,
            'sequence' => fn () => (int) Order::where('year', today()->year)->max('sequence') + 1,
            'number' => fn (array $attributes) => sprintf('%s-%d-%04d', SaveOrder::PREFIX, $attributes['year'], $attributes['sequence']),
            'status' => OrderStatus::Open,
            'organization_id' => Organization::factory(),
            'owner_id' => User::factory(),
            'customer' => [],
            'title' => fake()->randomElement(['Fornitura hardware', 'Installazione impianto', 'Progetto sito web']),
            'order_date' => today(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $rate = VatRate::default() ?? VatRate::factory()->create();

            app(SaveOrder::class)->handle($order, [
                'lines' => [
                    ['type' => 'item', 'description' => 'Installazione', 'unit' => 'h', 'quantity' => 10, 'unit_price' => 50, 'discount_percent' => 0, 'vat_rate_id' => $rate->id],
                    ['type' => 'item', 'description' => 'Materiale', 'unit' => 'pz', 'quantity' => 4, 'unit_price' => 125, 'discount_percent' => 0, 'vat_rate_id' => $rate->id],
                ],
            ]);
        });
    }
}
