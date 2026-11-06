<?php

namespace Database\Factories;

use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Conto corrente',
            'type' => FinancialAccountType::Bank,
            'opening_balance' => 0,
            'opening_date' => null,
            'is_active' => true,
        ];
    }
}
