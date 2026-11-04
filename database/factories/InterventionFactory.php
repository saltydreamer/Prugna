<?php

namespace Database\Factories;

use App\Enums\InterventionMode;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A completed remote intervention of 50 minutes (billed 1 h with the default rounding).
 *
 * @extends Factory<Intervention>
 */
class InterventionFactory extends Factory
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
            'category_id' => fn () => InterventionCategory::query()->first()?->id ?? InterventionCategory::create([
                'name' => 'Assistenza',
                'remote_product_id' => Product::factory()->service()->create(['name' => 'Assistenza da remoto', 'price' => 50])->id,
                'onsite_product_id' => Product::factory()->service()->create(['name' => 'Assistenza on site', 'price' => 70])->id,
            ])->id,
            'mode' => InterventionMode::Remote,
            'technician_id' => User::factory(),
            'started_at' => now()->subDays(2)->setTime(9, 0),
            'duration_minutes' => 50,
            'description' => 'Configurazione della posta sul nuovo PC',
            'billable' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Intervention $intervention): void {
            if (! $intervention->number) {
                // Made in bulk before saving: keep the sequence unique across the batch.
                $year = (int) $intervention->started_at->format('Y');
                $sequence = max((int) Intervention::query()->where('year', $year)->max('sequence'), self::$last[$year] ?? 0) + 1;
                self::$last[$year] = $sequence;

                $intervention->forceFill(['year' => $year, 'sequence' => $sequence, 'number' => sprintf('INT-%d-%04d', $year, $sequence)]);
            }
        });
    }

    public function onsite(): static
    {
        return $this->state(fn () => ['mode' => InterventionMode::Onsite]);
    }

    public function running(): static
    {
        return $this->state(fn () => ['started_at' => now()->subMinutes(40), 'duration_minutes' => null]);
    }
}
