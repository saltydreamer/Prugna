<?php

namespace Database\Factories;

use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatRate>
 */
class VatRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'IVA 22%',
            'rate' => 22,
            'nature' => null,
            'legal_reference' => null,
            'is_default' => false,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function exempt(string $nature = 'N4'): static
    {
        return $this->state(fn () => [
            'name' => "Esente {$nature}",
            'rate' => 0,
            'nature' => $nature,
            'legal_reference' => 'Esente art. 10 DPR 633/72',
        ]);
    }
}
