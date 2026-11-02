<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    private const CITIES = [
        ['Milano', 'MI', '20121'], ['Roma', 'RM', '00184'], ['Torino', 'TO', '10121'], ['Bologna', 'BO', '40121'],
        ['Firenze', 'FI', '50123'], ['Napoli', 'NA', '80133'], ['Verona', 'VR', '37121'], ['Bari', 'BA', '70121'],
        ['Padova', 'PD', '35121'], ['Brescia', 'BS', '25121'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$city, $province, $postalCode] = fake()->randomElement(self::CITIES);

        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->randomElement(['Sede centrale', 'Filiale', 'Stabilimento', 'Ufficio commerciale']),
            'type' => fake()->randomElement(LocationType::cases()),
            'is_primary' => false,
            'address' => 'Via '.fake()->lastName().' '.fake()->buildingNumber(),
            'postal_code' => $postalCode,
            'city' => $city,
            'province' => $province,
            'country' => 'IT',
            'email' => fake()->optional()->companyEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'notes' => null,
        ];
    }
}
