<?php

namespace Tests\Feature\Registry;

use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return [
            'name' => 'Acme S.r.l.',
            'type' => OrganizationType::Company->value,
            'vat_number' => '01114601006',
            'tax_code' => '',
            'sdi_code' => 'abc1234',
            'email' => 'info@acme.test',
            'pec' => 'acme@pec.test',
            'phone' => '+39 02 1234567',
            'website' => 'www.acme.test',
            'notes' => '',
            ...$overrides,
        ];
    }

    public function test_users_need_the_view_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/registry/organizations')
            ->assertForbidden();

        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->get('/registry/organizations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('registry/organizations/Index'));
    }

    public function test_index_searches_by_name_vat_and_city_and_lists_archived_separately(): void
    {
        $milan = Organization::factory()->create(['name' => 'Alfa Spa']);
        Location::factory()->for($milan)->create(['city' => 'Milano']);
        Organization::factory()->create(['name' => 'Beta Srl', 'vat_number' => '00743110157']);
        Organization::factory()->create(['name' => 'Gamma Snc'])->delete();

        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/registry/organizations?search=milano')
            ->assertInertia(fn (Assert $page) => $page->has('organizations.data', 1)->where('organizations.data.0.name', 'Alfa Spa'));

        $this->actingAs($user)->get('/registry/organizations?search=00743110157')
            ->assertInertia(fn (Assert $page) => $page->has('organizations.data', 1)->where('organizations.data.0.name', 'Beta Srl'));

        $this->actingAs($user)->get('/registry/organizations')
            ->assertInertia(fn (Assert $page) => $page->has('organizations.data', 2)->where('archivedCount', 1));

        $this->actingAs($user)->get('/registry/organizations?status=archived')
            ->assertInertia(fn (Assert $page) => $page->has('organizations.data', 1)->where('organizations.data.0.name', 'Gamma Snc'));
    }

    public function test_create_and_edit_pages_render(): void
    {
        $user = User::factory()->admin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($user)->get('/registry/organizations/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('registry/organizations/Create')->has('types', count(OrganizationType::cases())));

        $this->actingAs($user)->get("/registry/organizations/{$organization->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('registry/organizations/Edit')->where('organization.id', $organization->id));
    }

    public function test_organizations_can_be_created_with_normalized_codes(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/registry/organizations', $this->validData(['vat_number' => 'IT 011 146 010 06']));

        $organization = Organization::firstOrFail();
        $response->assertRedirect("/registry/organizations/{$organization->id}");

        $this->assertSame('01114601006', $organization->vat_number);
        $this->assertSame('ABC1234', $organization->sdi_code);
        $this->assertSame('https://www.acme.test', $organization->website);
        $this->assertTrue($organization->creator->is($user));
    }

    public function test_fiscal_codes_are_validated(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post('/registry/organizations', $this->validData(['vat_number' => '12345678901', 'tax_code' => 'RSSMRA80A01H501X', 'sdi_code' => '123']))
            ->assertSessionHasErrors(['vat_number', 'tax_code', 'sdi_code']);

        $this->actingAs($user)
            ->post('/registry/organizations', $this->validData(['vat_number' => 'DE123456789', 'tax_code' => 'RSSMRA80A01H501U']))
            ->assertSessionHasNoErrors();
    }

    public function test_vat_numbers_are_unique_even_among_archived_organizations(): void
    {
        Organization::factory()->create(['vat_number' => '01114601006'])->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/registry/organizations', $this->validData())
            ->assertSessionHasErrors('vat_number');
    }

    public function test_read_only_users_can_not_create_or_update(): void
    {
        $user = User::factory()->withRole(Role::User)->create();
        $organization = Organization::factory()->create();

        $this->actingAs($user)->post('/registry/organizations', $this->validData())->assertForbidden();
        $this->actingAs($user)->put("/registry/organizations/{$organization->id}", $this->validData())->assertForbidden();
        $this->actingAs($user)->get("/registry/organizations/{$organization->id}")->assertOk();
    }

    public function test_show_page_includes_locations_and_contacts(): void
    {
        $organization = Organization::factory()->withDetails(locations: 2, contacts: 3)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/registry/organizations/{$organization->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registry/organizations/Show')
                ->has('organization.locations', 2)
                ->has('organization.contacts', 3)
                ->where('organization.locations.0.is_primary', true)
                ->where('organization.can.update', true)
                ->has('locationTypes'));
    }

    public function test_organizations_can_be_updated(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put("/registry/organizations/{$organization->id}", $this->validData(['name' => 'Nuovo nome', 'vat_number' => $organization->vat_number]))
            ->assertRedirect("/registry/organizations/{$organization->id}");

        $this->assertSame('Nuovo nome', $organization->fresh()->name);
    }

    public function test_archived_organizations_are_read_only_until_restored(): void
    {
        $user = User::factory()->admin()->create();
        $organization = Organization::factory()->withDetails(1, 1)->create();

        $this->actingAs($user)->delete("/registry/organizations/{$organization->id}")->assertRedirect('/registry/organizations');
        $this->assertSoftDeleted($organization);

        // Still visible, but read-only.
        $this->actingAs($user)->get("/registry/organizations/{$organization->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('organization.can.update', false)->where('organization.can.restore', true));
        $this->actingAs($user)->put("/registry/organizations/{$organization->id}", $this->validData())->assertNotFound();

        // Its contacts disappear from the address book.
        $this->actingAs($user)->get('/registry/contacts')->assertInertia(fn (Assert $page) => $page->has('contacts.data', 0));

        $this->actingAs($user)->patch("/registry/organizations/{$organization->id}/restore")->assertRedirect("/registry/organizations/{$organization->id}");
        $this->assertNotSoftDeleted($organization);
        $this->assertSame(1, Contact::count());
    }

    public function test_archiving_requires_the_delete_permission(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->delete("/registry/organizations/{$organization->id}")
            ->assertForbidden();
    }
}
