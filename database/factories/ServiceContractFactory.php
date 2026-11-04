<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ServiceContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 10 hours valid for a year, any mode and category.
 *
 * @extends Factory<ServiceContract>
 */
class ServiceContractFactory extends Factory
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
            'title' => 'Pacchetto 10 ore',
            'hours' => 10,
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->addMonths(11),
            'price' => 450,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ServiceContract $contract): void {
            $year = (int) $contract->starts_on->format('Y');
            $sequence = max((int) ServiceContract::query()->where('year', $year)->max('sequence'), self::$last[$year] ?? 0) + 1;
            self::$last[$year] = $sequence;

            $contract->forceFill(['year' => $year, 'sequence' => $sequence, 'number' => sprintf('MO-%d-%04d', $year, $sequence)]);
        });
    }
}
