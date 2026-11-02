<?php

namespace Database\Factories;

use App\Actions\Quotes\SaveQuote;
use App\Enums\QuoteStatus;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * After creation the quote goes through SaveQuote, so it has customer data, lines and totals.
 *
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence' => fn () => (int) Quote::max('sequence') + 1,
            'number' => fn (array $attributes) => SaveQuote::NUMBER_PREFIX.str_pad((string) $attributes['sequence'], 5, '0', STR_PAD_LEFT),
            'status' => QuoteStatus::Draft,
            'organization_id' => Organization::factory(),
            'contact_id' => null,
            'owner_id' => User::factory(),
            'customer' => [],
            'title' => fake()->randomElement(['Fornitura hardware', 'Servizi di assistenza', 'Progetto sito web', 'Manutenzione annuale']),
            'issue_date' => today(),
            'valid_until' => today()->addDays(30),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Quote $quote): void {
            $rate = VatRate::default() ?? VatRate::factory()->create();

            app(SaveQuote::class)->handle($quote, [
                'lines' => [
                    ['type' => 'section', 'description' => 'Fornitura'],
                    ['type' => 'item', 'description' => 'Consulenza tecnica', 'unit' => 'h', 'quantity' => 10, 'unit_price' => 80, 'discount_percent' => 0, 'vat_rate_id' => $rate->id],
                    ['type' => 'item', 'description' => 'Licenza annuale', 'unit' => 'pz', 'quantity' => 1, 'unit_price' => 200, 'discount_percent' => 10, 'vat_rate_id' => $rate->id],
                    ['type' => 'item', 'description' => 'Formazione (facoltativa)', 'unit' => 'h', 'quantity' => 4, 'unit_price' => 60, 'discount_percent' => 0, 'vat_rate_id' => $rate->id, 'is_optional' => true],
                ],
            ]);
        });
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => QuoteStatus::Sent, 'sent_at' => now()]);
    }

    public function expired(): static
    {
        return $this->sent()->state(fn () => ['issue_date' => today()->subDays(40), 'valid_until' => today()->subDays(10)]);
    }
}
