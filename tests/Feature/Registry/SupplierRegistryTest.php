<?php

namespace Tests\Feature\Registry;

use App\Enums\OrganizationRole;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customers and suppliers are separate registries: an organization is one or the other.
 */
class SupplierRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_and_suppliers_are_listed_separately(): void
    {
        $customer = Organization::factory()->create();
        $supplier = Organization::factory()->supplier()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/registry/customers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('role.value', 'customer')
                ->has('organizations.data', 1)
                ->where('organizations.data.0.id', $customer->id));

        $this->actingAs($admin)->get('/registry/suppliers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('role.value', 'supplier')
                ->has('organizations.data', 1)
                ->where('organizations.data.0.id', $supplier->id));

        $this->actingAs($admin)->get('/registry/organizations')->assertRedirect('/registry/customers');
    }

    public function test_suppliers_have_their_own_permissions(): void
    {
        $supplier = Organization::factory()->supplier()->create();
        Contact::factory()->for($supplier)->create();
        Contact::factory()->for(Organization::factory()->create())->create();
        $salesperson = User::factory()->withRole(Role::User)->create();

        $this->actingAs($salesperson)->get('/registry/customers')->assertOk();
        $this->actingAs($salesperson)->get('/registry/suppliers')->assertForbidden();
        $this->actingAs($salesperson)->get("/registry/organizations/{$supplier->id}")->assertForbidden();
        // Contacts of suppliers stay hidden too.
        $this->actingAs($salesperson)->get('/registry/contacts')->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));

        $salesperson->givePermissionTo('suppliers.view');
        $this->actingAs($salesperson)->get("/registry/organizations/{$supplier->id}")->assertOk();
        $this->actingAs($salesperson)->post('/registry/organizations', ['name' => 'Ferramenta Rossi', 'type' => 'company', 'role' => 'supplier'])->assertForbidden();
    }

    public function test_a_supplier_is_created_in_its_registry(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/registry/organizations', ['name' => 'Ferramenta Rossi', 'type' => 'company', 'role' => 'supplier'])
            ->assertRedirect();

        $this->assertSame(OrganizationRole::Supplier, Organization::sole()->role);
    }

    public function test_the_role_changes_only_while_there_are_no_documents(): void
    {
        $admin = User::factory()->admin()->create();
        $organization = Organization::factory()->create();
        $data = fn (string $role) => ['name' => $organization->name, 'type' => 'company', 'vat_number' => $organization->vat_number, 'role' => $role];

        $this->actingAs($admin)->put("/registry/organizations/{$organization->id}", $data('supplier'))->assertRedirect();
        $this->assertSame(OrganizationRole::Supplier, $organization->fresh()->role);

        PurchaseOrder::factory()->create(['supplier_id' => $organization->id]);

        $this->actingAs($admin)->put("/registry/organizations/{$organization->id}", $data('customer'))->assertRedirect();
        $this->assertSame(OrganizationRole::Supplier, $organization->fresh()->role);
    }

    public function test_documents_accept_only_the_right_registry(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Organization::factory()->create();
        $supplier = Organization::factory()->supplier()->create();
        $line = ['type' => 'item', 'description' => 'Riga', 'quantity' => 1, 'unit_price' => 10, 'vat_rate_id' => VatRate::default()->id];

        $this->actingAs($admin)->post('/sales/quotes', [
            'organization_id' => $supplier->id,
            'issue_date' => today()->toDateString(),
            'valid_until' => today()->addMonth()->toDateString(),
            'lines' => [$line],
        ])->assertSessionHasErrors('organization_id');
        $this->assertSame(0, Quote::count());

        $this->actingAs($admin)->post('/purchases/orders', [
            'supplier_id' => $customer->id,
            'order_date' => today()->toDateString(),
            'lines' => [$line],
        ])->assertSessionHasErrors('supplier_id');

        $this->actingAs($admin)->post('/purchases/orders', [
            'supplier_id' => $supplier->id,
            'customer_id' => Organization::factory()->supplier()->create()->id,
            'order_date' => today()->toDateString(),
            'lines' => [$line],
        ])->assertSessionHasErrors('customer_id');

        $this->actingAs($admin)->get('/purchases/orders/create')
            ->assertInertia(fn (Assert $page) => $page
                // Two suppliers, one customer.
                ->has('organizations', 2)
                ->has('customers', 1)
                ->where('customers.0.value', $customer->id));
    }
}
