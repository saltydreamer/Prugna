<?php

namespace Database\Factories;

use App\Actions\Purchases\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * After creation the order goes through SavePurchaseOrder (supplier snapshot, lines, totals).
 * Lines: 2 × 100 € to rebill with 10% markup, 1 × 50 € internal cost.
 *
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
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
            'sequence' => fn () => (int) PurchaseOrder::where('year', today()->year)->max('sequence') + 1,
            'number' => fn (array $attributes) => sprintf('%s-%d-%04d', SavePurchaseOrder::PREFIX, $attributes['year'], $attributes['sequence']),
            'status' => PurchaseOrderStatus::Draft,
            'supplier_id' => Organization::factory()->supplier(),
            'owner_id' => User::factory(),
            'supplier' => [],
            'title' => 'Materiale di installazione',
            'order_date' => today(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (PurchaseOrder $purchaseOrder): void {
            $rate = VatRate::default() ?? VatRate::factory()->create();

            app(SavePurchaseOrder::class)->handle($purchaseOrder, [
                'lines' => [
                    ['type' => 'item', 'description' => 'Switch 24 porte', 'unit' => 'pz', 'quantity' => 2, 'unit_price' => 100, 'vat_rate_id' => $rate->id, 'rebill' => true, 'markup_percent' => 10],
                    ['type' => 'item', 'description' => 'Minuteria', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 50, 'vat_rate_id' => $rate->id, 'rebill' => false],
                ],
            ]);
        });
    }

    /**
     * Sent to the supplier, its costs can be charged back to the customer.
     */
    public function sent(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);
    }
}
