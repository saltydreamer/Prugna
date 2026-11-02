<?php

namespace Database\Factories;

use App\Actions\Invoices\SaveInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft invoice saved through SaveInvoice (fiscal data, lines, totals).
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => InvoiceType::Invoice,
            'status' => InvoiceStatus::Draft,
            'series' => 'FT',
            'issue_date' => today(),
            'organization_id' => Organization::factory()->withDetails(1, 1),
            'owner_id' => User::factory(),
            'customer' => [],
            'regime' => 'RF01',
            'title' => 'Servizi di assistenza',
            'payment_term_id' => fn () => PaymentTerm::default()?->id,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice): void {
            $rate = VatRate::default() ?? VatRate::factory()->create();

            $saveInvoice = app(SaveInvoice::class);

            $saveInvoice->handle($invoice, [
                ...$saveInvoice->defaultsFor($invoice->organization),
                'lines' => [
                    ['type' => 'item', 'description' => 'Assistenza tecnica', 'unit' => 'h', 'quantity' => 8, 'unit_price' => 60, 'discount_percent' => 0, 'vat_rate_id' => $rate->id],
                ],
            ]);
        });
    }
}
