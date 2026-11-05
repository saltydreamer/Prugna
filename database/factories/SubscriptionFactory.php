<?php

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Enums\QuoteLineType;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Monthly fee in advance from the first day of this month, no end.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /** @var array<int, int> */
    private static array $last = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'title' => 'Hosting e manutenzione sito',
            'billing_period' => BillingPeriod::Monthly,
            'billing_mode' => Subscription::MODE_ADVANCE,
            'starts_on' => today()->startOfMonth(),
            'ends_on' => null,
            'billed_until' => null,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $subscription): void {
            $year = (int) today()->format('Y');
            $sequence = max((int) Subscription::query()->where('year', $year)->max('sequence'), self::$last[$year] ?? 0) + 1;
            self::$last[$year] = $sequence;

            $subscription->forceFill(['year' => $year, 'sequence' => $sequence, 'number' => sprintf('CS-%d-%04d', $year, $sequence)]);
        });
    }

    /**
     * One service line at the given price per period.
     */
    public function withService(float $price = 50, string $description = 'Hosting sito web', ?int $vatRateId = null): static
    {
        return $this->afterCreating(function (Subscription $subscription) use ($price, $description, $vatRateId): void {
            $rate = $vatRateId ? VatRate::query()->find($vatRateId) : (VatRate::default() ?? VatRate::factory()->create());

            $subscription->lines()->create([
                'position' => $subscription->lines()->count() + 1,
                'type' => QuoteLineType::Item,
                'description' => $description,
                'unit' => 'pz',
                'quantity' => 1,
                'unit_price' => $price,
                'discount_percent' => 0,
                'vat_rate_id' => $rate->id,
                'vat_rate' => $rate->rate,
                'vat_nature' => $rate->nature,
                'total' => $price,
            ]);
        });
    }
}
