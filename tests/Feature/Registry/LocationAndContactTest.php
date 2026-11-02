<?php

namespace Tests\Feature\Registry;

use App\Enums\LocationType;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocationAndContactTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function location(array $overrides = []): array
    {
        return [
            'name' => 'Sede legale',
            'type' => LocationType::Registered->value,
            'is_primary' => false,
            'address' => 'Via Roma 1',
            'postal_code' => '20121',
            'city' => 'Milano',
            'province' => 'mi',
            'country' => 'IT',
            ...$overrides,
        ];
    }

    public function test_the_first_location_becomes_primary_and_primary_can_be_moved(): void
    {
        $user = User::factory()->admin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($user)->post("/registry/organizations/{$organization->id}/locations", $this->location())->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/registry/organizations/{$organization->id}/locations", $this->location(['name' => 'Magazzino']))->assertSessionHasNoErrors();

        [$first, $second] = $organization->locations()->orderBy('id')->get();
        $this->assertTrue($first->is_primary);
        $this->assertFalse($second->is_primary);
        $this->assertSame('MI', $first->province);
        $this->assertSame('Via Roma 1, 20121 Milano (MI)', $first->full_address);

        $this->actingAs($user)->put("/registry/organizations/{$organization->id}/locations/{$second->id}", $this->location(['name' => 'Magazzino', 'is_primary' => true]));

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_deleting_the_primary_location_promotes_another_one(): void
    {
        $organization = Organization::factory()->create();
        $first = Location::factory()->for($organization)->create();
        $second = Location::factory()->for($organization)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete("/registry/organizations/{$organization->id}/locations/{$first->id}")
            ->assertRedirect();

        $this->assertModelMissing($first);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_italian_addresses_require_cap_and_province(): void
    {
        $user = User::factory()->admin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($user)
            ->post("/registry/organizations/{$organization->id}/locations", $this->location(['postal_code' => '201', 'province' => 'Milano']))
            ->assertSessionHasErrors(['postal_code', 'province']);

        $this->actingAs($user)
            ->post("/registry/organizations/{$organization->id}/locations", $this->location(['country' => 'ch', 'postal_code' => '', 'province' => '', 'city' => 'Lugano']))
            ->assertSessionHasNoErrors();

        $this->assertSame('CH', $organization->locations()->firstOrFail()->country);
    }

    public function test_nested_resources_are_scoped_to_their_organization(): void
    {
        $user = User::factory()->admin()->create();
        $organization = Organization::factory()->create();
        $foreignLocation = Location::factory()->create();
        $foreignContact = Contact::factory()->create();

        $this->actingAs($user)->put("/registry/organizations/{$organization->id}/locations/{$foreignLocation->id}", $this->location())->assertNotFound();
        $this->actingAs($user)->delete("/registry/organizations/{$organization->id}/contacts/{$foreignContact->id}")->assertNotFound();

        // A contact can't be linked to a location of another organization.
        $this->actingAs($user)
            ->post("/registry/organizations/{$organization->id}/contacts", ['first_name' => 'Mario', 'last_name' => 'Rossi', 'location_id' => $foreignLocation->id])
            ->assertSessionHasErrors('location_id');
    }

    public function test_contacts_can_be_managed(): void
    {
        $user = User::factory()->admin()->create();
        $organization = Organization::factory()->create();
        $location = Location::factory()->for($organization)->create();

        $this->actingAs($user)->post("/registry/organizations/{$organization->id}/contacts", [
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'job_title' => 'Responsabile acquisti',
            'email' => 'mario.rossi@example.com',
            'mobile' => '+39 333 1234567',
            'location_id' => $location->id,
        ])->assertSessionHasNoErrors();

        $contact = $organization->contacts()->firstOrFail();
        $this->assertTrue($contact->is_primary);

        // The new contact is returned so that the page can select it (e.g. in the quote editor).
        $this->actingAs($user)->get("/registry/organizations/{$organization->id}")
            ->assertInertia(fn (Assert $page) => $page->hasFlash('createdContact', [
                'value' => $contact->id, 'title' => 'Mario Rossi', 'subtitle' => 'mario.rossi@example.com',
            ]));
        $this->assertTrue($contact->location->is($location));

        $this->actingAs($user)->put("/registry/organizations/{$organization->id}/contacts/{$contact->id}", [
            'first_name' => 'Mario',
            'last_name' => 'Bianchi',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors('email');

        $this->actingAs($user)->delete("/registry/organizations/{$organization->id}/contacts/{$contact->id}")->assertRedirect();
        $this->assertModelMissing($contact);
    }

    public function test_read_only_users_can_not_change_locations_or_contacts(): void
    {
        $user = User::factory()->withRole(Role::User)->create();
        $organization = Organization::factory()->create();

        $this->actingAs($user)->post("/registry/organizations/{$organization->id}/locations", $this->location())->assertForbidden();
        $this->actingAs($user)->post("/registry/organizations/{$organization->id}/contacts", ['first_name' => 'A', 'last_name' => 'B'])->assertForbidden();
    }

    public function test_the_address_book_searches_by_full_name_and_organization(): void
    {
        $acme = Organization::factory()->create(['name' => 'Acme']);
        Contact::factory()->for($acme)->create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
        Contact::factory()->for($acme)->create(['first_name' => 'Maria', 'last_name' => 'Verdi']);
        Contact::factory()->create(['first_name' => 'Luca', 'last_name' => 'Rossi']);

        $user = User::factory()->withRole(Role::User)->create();

        $this->actingAs($user)->get('/registry/contacts?search=mario+rossi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registry/contacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.organization.name', 'Acme'));

        $this->actingAs($user)->get('/registry/contacts?search=acme')
            ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 2));

        $this->actingAs($user)->get('/registry/contacts?primary=1')
            ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 2));
    }
}
