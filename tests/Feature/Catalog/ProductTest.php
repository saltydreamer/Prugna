<?php

namespace Tests\Feature\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return [
            'type' => 'product',
            'code' => '',
            'name' => 'Notebook 14"',
            'description' => 'Notebook per ufficio',
            'category_id' => null,
            'unit' => 'pz',
            'price' => '899.90',
            'cost' => '650',
            'vat_rate_id' => VatRate::default()->id,
            'billing_period' => null,
            'is_active' => true,
            'notes' => '',
            ...$overrides,
        ];
    }

    public function test_vat_rates_are_seeded_with_natures_for_exempt_operations(): void
    {
        $this->assertSame('22.00', VatRate::default()->rate);
        $this->assertSame(0, VatRate::query()->where('rate', 0)->whereNull('nature')->count());
        $this->assertTrue(VatRate::query()->where('nature', 'N2.2')->exists());
    }

    public function test_catalog_requires_permission_and_users_can_only_read(): void
    {
        $this->actingAs(User::factory()->create())->get('/catalog/products')->assertForbidden();

        $reader = User::factory()->withRole(Role::User)->create();
        $this->actingAs($reader)->get('/catalog/products')->assertOk()->assertInertia(fn (Assert $page) => $page->component('catalog/products/Index'));
        $this->actingAs($reader)->post('/catalog/products', $this->validData())->assertForbidden();
        $this->actingAs($reader)->get('/catalog/settings')->assertForbidden();
    }

    public function test_products_are_created_with_a_generated_code(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/catalog/products/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('catalog/products/Create')->where('nextCodes.product', 'P00001')->where('nextCodes.service', 'S00001'));

        $this->actingAs($admin)->post('/catalog/products', $this->validData())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/catalog/products', $this->validData(['name' => 'Mouse']))->assertSessionHasNoErrors();

        $this->assertSame(['P00001', 'P00002'], Product::orderBy('id')->pluck('code')->all());

        $product = Product::firstOrFail();
        $this->assertSame('899.9000', $product->price);
        $this->assertEquals(['amount' => 249.9, 'percent' => 27.8], $product->margin());
    }

    public function test_custom_codes_are_uppercase_and_unique(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['code' => 'NB-14']);

        $this->actingAs($admin)->post('/catalog/products', $this->validData(['code' => 'nb-14']))->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/catalog/products', $this->validData(['code' => 'nb-15']))->assertSessionHasNoErrors();
        $this->assertTrue(Product::where('code', 'NB-15')->exists());
    }

    public function test_only_services_can_be_recurring(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/catalog/products', $this->validData(['type' => 'product', 'billing_period' => 'monthly']));
        $this->assertNull(Product::firstOrFail()->billing_period);

        $this->actingAs($admin)->post('/catalog/products', $this->validData([
            'type' => 'service', 'name' => 'Canone assistenza', 'unit' => 'mese', 'billing_period' => 'monthly',
        ]))->assertSessionHasNoErrors();

        $service = Product::where('type', 'service')->firstOrFail();
        $this->assertSame(BillingPeriod::Monthly, $service->billing_period);
        $this->assertSame('S00001', $service->code);
    }

    public function test_inactive_vat_rates_can_not_be_chosen(): void
    {
        $inactive = VatRate::factory()->create(['is_active' => false]);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/catalog/products', $this->validData(['vat_rate_id' => $inactive->id]))
            ->assertSessionHasErrors('vat_rate_id');
    }

    public function test_index_filters_by_search_category_status_and_recurring(): void
    {
        $category = ProductCategory::factory()->create();
        Product::factory()->create(['name' => 'Stampante laser', 'category_id' => $category->id]);
        Product::factory()->inactive()->create(['name' => 'Stampante vecchia']);
        Product::factory()->recurring()->create(['name' => 'Canone hosting']);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/catalog/products?search=stampante')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->actingAs($admin)->get('/catalog/products?search=stampante&status=all')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
        $this->actingAs($admin)->get("/catalog/products?category={$category->id}")
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.category.id', $category->id));
        $this->actingAs($admin)->get('/catalog/products?recurring=1')
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.billing_period', 'monthly'));
    }

    public function test_products_can_be_shown_updated_duplicated_and_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['code' => 'P00007']);

        $this->actingAs($admin)->get("/catalog/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('catalog/products/Show')->where('product.code', 'P00007')->missing('organizationOptions'));

        $this->actingAs($admin)->get("/catalog/products/{$product->id}/edit")->assertOk();

        $this->actingAs($admin)->put("/catalog/products/{$product->id}", $this->validData(['code' => 'P00007', 'name' => 'Nuovo nome']))
            ->assertRedirect("/catalog/products/{$product->id}");
        $this->assertSame('Nuovo nome', $product->fresh()->name);

        $this->actingAs($admin)->post("/catalog/products/{$product->id}/duplicate")->assertRedirect();
        $copy = Product::where('code', 'P00008')->firstOrFail();
        $this->assertSame('Nuovo nome (copia)', $copy->name);

        $this->actingAs($admin)->delete("/catalog/products/{$product->id}")->assertRedirect('/catalog/products');
        $this->assertModelMissing($product);
    }
}
