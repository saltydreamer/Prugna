<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'type' => OrganizationType::Company,
            'role' => OrganizationRole::Customer,
            'vat_number' => self::partitaIva(),
            'tax_code' => null,
            'sdi_code' => strtoupper(fake()->bothify('???????')),
            'email' => fake()->companyEmail(),
            'pec' => fake()->optional()->userName().'@pec.example.com',
            'phone' => fake()->phoneNumber(),
            'website' => fake()->optional()->url(),
            'notes' => null,
        ];
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['role' => OrganizationRole::Supplier]);
    }

    /**
     * Adds locations and contacts, the first ones being primary.
     */
    public function withDetails(int $locations = 2, int $contacts = 3): static
    {
        return $this->afterCreating(function (Organization $organization) use ($locations, $contacts): void {
            Location::factory($locations)->for($organization)->create();
            Contact::factory($contacts)->for($organization)->create();
        });
    }

    /**
     * A random Partita IVA with a valid check digit.
     */
    public static function partitaIva(): string
    {
        $digits = (string) fake()->numerify('##########');
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $digits[$i];

            if ($i % 2 === 1) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
        }

        return $digits.((10 - $sum % 10) % 10);
    }
}
