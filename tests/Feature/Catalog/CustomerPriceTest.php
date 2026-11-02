<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ResolvePrice;
use App\Enums\Role;
use App\Models\CustomerPrice;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_price_resolver_applies_agreed_prices_and_discounts(): void
    {
        $product = Product::factory()->create(['price' => 100]);
        $fixed = Organization::factory()->create();
        $discounted = Organization::factory()->create();
        $expired = Organization::factory()->create();
        $other = Organization::factory()->create();

        CustomerPrice::factory()->for($fixed)->for($product)->create(['price' => 80, 'discount_percent' => null]);
        CustomerPrice::factory()->for($discounted)->for($product)->create(['discount_percent' => 15]);
        CustomerPrice::factory()->for($expired)->for($product)->create(['discount_percent' => 50, 'valid_until' => now()->subDay()]);

        $resolve = app(ResolvePrice::class);

        $this->assertSame(['price' => 80.0, 'source' => 'customer_price'], array_intersect_key($resolve->handle($product, $fixed), ['price' => 0, 'source' => 0]));
        $this->assertSame(['price' => 85.0, 'source' => 'customer_discount'], array_intersect_key($resolve->handle($product, $discounted), ['price' => 0, 'source' => 0]));
        $this->assertSame('list', $resolve->handle($product, $expired)['source']);
        $this->assertSame('list', $resolve->handle($product, $other)['source']);
        $this->assertSame(100.0, $resolve->handle($product)['price']);
    }

    public function test_agreed_prices_can_be_managed(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['price' => 50]);
        $organization = Organization::factory()->create();

        $this->actingAs($admin)->post('/catalog/customer-prices', [
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'mode' => 'discount',
            'discount_percent' => 10,
            'price' => 999,
        ])->assertSessionHasNoErrors();

        $agreement = CustomerPrice::firstOrFail();
        $this->assertNull($agreement->price, 'Only the value of the chosen mode is stored.');
        $this->assertSame('10.00', $agreement->discount_percent);

        // One agreement per customer and product.
        $this->actingAs($admin)->post('/catalog/customer-prices', [
            'organization_id' => $organization->id, 'product_id' => $product->id, 'mode' => 'price', 'price' => 40,
        ])->assertSessionHasErrors('organization_id');

        $this->actingAs($admin)->put("/catalog/customer-prices/{$agreement->id}", ['mode' => 'price', 'price' => 42])->assertSessionHasNoErrors();
        $this->assertSame('42.0000', $agreement->fresh()->price);
        $this->assertNull($agreement->fresh()->discount_percent);

        $this->actingAs($admin)->delete("/catalog/customer-prices/{$agreement->id}")->assertRedirect();
        $this->assertModelMissing($agreement);
    }

    public function test_agreed_prices_are_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($admin)->post('/catalog/customer-prices', [
            'organization_id' => $organization->id, 'product_id' => $product->id, 'mode' => 'discount', 'discount_percent' => 150, 'valid_until' => '2000-01-01',
        ])->assertSessionHasErrors(['discount_percent', 'valid_until']);

        $this->actingAs($admin)->post('/catalog/customer-prices', [
            'organization_id' => $organization->id, 'product_id' => $product->id, 'mode' => 'price',
        ])->assertSessionHasErrors('price');
    }

    public function test_read_only_users_can_not_change_agreed_prices(): void
    {
        $agreement = CustomerPrice::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::User)->create())
            ->delete("/catalog/customer-prices/{$agreement->id}")
            ->assertForbidden();
    }

    public function test_agreed_prices_show_on_product_and_organization_pages_with_lazy_options(): void
    {
        $admin = User::factory()->admin()->create();
        $agreement = CustomerPrice::factory()->create(['discount_percent' => 20]);
        $agreement->product->update(['price' => 200]);

        $this->actingAs($admin)->get("/catalog/products/{$agreement->product_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('customerPrices', 1)
                ->where('customerPrices.0.effective_price', 160)
                ->where('customerPrices.0.organization.id', $agreement->organization_id));

        $this->actingAs($admin)->get("/registry/organizations/{$agreement->organization_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('customerPrices', 1)
                ->where('customerPrices.0.product.id', $agreement->product_id)
                ->missing('productOptions')
                // The options are sent only on demand (partial reload when the dialog opens).
                ->reloadOnly('productOptions', fn (Assert $reload) => $reload
                    ->where('productOptions.0.value', $agreement->product_id)));
    }

    public function test_organization_page_hides_prices_without_catalog_permission(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $user->givePermissionTo('organizations.view');

        $this->actingAs($user)->get("/registry/organizations/{$organization->id}")
            ->assertInertia(fn (Assert $page) => $page->where('customerPrices', null));
    }
}
