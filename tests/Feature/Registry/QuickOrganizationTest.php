<?php

namespace Tests\Feature\Registry;

use App\Enums\LocationType;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_is_created_with_office_and_contact_and_returned_to_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $term = PaymentTerm::where('method', 'MP12')->firstOrFail();

        $this->actingAs($admin)
            ->from('/sales/quotes/create')
            ->post('/registry/organizations/quick', [
                'name' => 'Nuovo Cliente S.r.l.',
                'type' => 'company',
                'vat_number' => 'IT01114601006',
                'payment_term_id' => $term->id,
                'location' => ['address' => 'Via Torino 5', 'postal_code' => '10121', 'city' => 'Torino', 'province' => 'to', 'country' => 'IT'],
                'contact' => ['first_name' => 'Anna', 'last_name' => 'Neri', 'email' => 'anna@cliente.test'],
            ])
            ->assertRedirect('/sales/quotes/create')
            ->assertSessionHasNoErrors();

        $organization = Organization::where('name', 'Nuovo Cliente S.r.l.')->firstOrFail();
        $this->assertSame('01114601006', $organization->vat_number);
        $this->assertTrue($organization->paymentTerm->is($term));
        $this->assertTrue($organization->creator->is($admin));

        $office = $organization->locations()->firstOrFail();
        $this->assertSame(LocationType::Registered, $office->type);
        $this->assertTrue($office->is_primary);
        $this->assertSame('TO', $office->province);

        $contact = $organization->contacts()->firstOrFail();
        $this->assertSame('Anna Neri', $contact->full_name);
        $this->assertTrue($contact->is_primary);

        // The editor receives the new customer and its updated list.
        $this->actingAs($admin)->get('/sales/quotes/create')
            ->assertInertia(fn (Assert $page) => $page
                ->hasFlash('createdOrganization', [
                    'value' => $organization->id,
                    'title' => 'Nuovo Cliente S.r.l.',
                    'subtitle' => 'P.IVA 01114601006',
                ])
                ->where('organizations', fn ($options) => collect($options)->contains('value', $organization->id)));
    }

    public function test_office_and_contact_are_optional(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/registry/organizations/quick', ['name' => 'Privato', 'type' => 'other', 'location' => ['country' => 'IT'], 'contact' => null])
            ->assertSessionHasNoErrors();

        $organization = Organization::firstOrFail();
        $this->assertSame(0, $organization->locations()->count());
        $this->assertSame(0, $organization->contacts()->count());
    }

    public function test_data_is_validated_like_the_registry(): void
    {
        Organization::factory()->create(['vat_number' => '01114601006']);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/registry/organizations/quick', [
                'name' => '',
                'type' => 'company',
                'vat_number' => '01114601006',
                'location' => ['address' => 'Via Roma 1', 'postal_code' => '123', 'city' => 'Roma', 'country' => 'IT'],
                'contact' => ['email' => 'mario@test.it'],
            ])
            ->assertSessionHasErrors(['name', 'vat_number', 'location.postal_code', 'location.province', 'contact.first_name', 'contact.last_name']);

        $this->assertSame(1, Organization::count());
    }

    public function test_it_requires_the_create_permission(): void
    {
        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->post('/registry/organizations/quick', ['name' => 'X', 'type' => 'company'])
            ->assertForbidden();
    }
}
