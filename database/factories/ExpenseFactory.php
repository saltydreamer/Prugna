<?php

namespace Database\Factories;

use App\Actions\Expenses\SaveExpense;
use App\Enums\ExpenseType;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A supplier invoice saved through SaveExpense: one line of 100 € + VAT to rebill with 20% markup.
 *
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ExpenseType::Invoice,
            'supplier_id' => Organization::factory()->supplier(),
            'supplier' => [],
            'number' => fn () => 'F'.fake()->unique()->numberBetween(100, 99999),
            'document_date' => today()->subDays(10),
            'payment_method' => PaymentMethod::BankTransfer,
            'due_date' => today()->addDays(20),
            'owner_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Expense $expense): void {
            $rate = VatRate::default() ?? VatRate::factory()->create();

            app(SaveExpense::class)->handle($expense, [
                'lines' => [
                    ['type' => 'item', 'description' => 'Cavi e connettori', 'quantity' => 1, 'unit_price' => 100, 'vat_rate_id' => $rate->id, 'rebill' => true, 'markup_percent' => 20],
                ],
            ]);
        });
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['document_date' => today()->subDays(60), 'due_date' => today()->subDays(5)]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['paid_at' => today()->subDay()]);
    }
}
