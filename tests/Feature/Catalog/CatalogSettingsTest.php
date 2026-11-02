<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_lists_categories_and_vat_rates(): void
    {
        ProductCategory::factory()->create(['name' => 'Hardware']);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/catalog/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/Settings')
                ->has('categories', 1)
                ->has('vatRates', VatRate::count())
                ->has('natures'));
    }

    public function test_categories_can_be_managed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/catalog/settings/categories', ['name' => 'Licenze', 'color' => 'info'])->assertSessionHasNoErrors();
        $category = ProductCategory::firstOrFail();
        $this->actingAs($admin)->post('/catalog/settings/categories', ['name' => 'Licenze', 'color' => 'info'])->assertSessionHasErrors('name');

        $product = Product::factory()->create(['category_id' => $category->id]);
        $this->actingAs($admin)->delete("/catalog/settings/categories/{$category->id}")->assertRedirect();

        $this->assertModelMissing($category);
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_exempt_rates_require_nature_and_legal_reference(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/catalog/settings/vat-rates', ['name' => 'Esente', 'rate' => 0])
            ->assertSessionHasErrors(['nature', 'legal_reference']);

        $this->actingAs($admin)->post('/catalog/settings/vat-rates', ['name' => 'IVA 22%', 'rate' => 22, 'nature' => 'N4'])
            ->assertSessionHasErrors('nature');

        $this->actingAs($admin)->post('/catalog/settings/vat-rates', [
            'name' => 'Esente medico', 'rate' => 0, 'nature' => 'N4', 'legal_reference' => 'Esente art. 10 n. 18 DPR 633/72',
        ])->assertSessionHasNoErrors();
    }

    public function test_there_is_a_single_default_rate(): void
    {
        $admin = User::factory()->admin()->create();
        $ten = VatRate::where('rate', 10)->firstOrFail();

        $this->actingAs($admin)->put("/catalog/settings/vat-rates/{$ten->id}", [
            'name' => $ten->name, 'rate' => 10, 'is_default' => true, 'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, VatRate::where('is_default', true)->count());
        $this->assertTrue(VatRate::default()->is($ten));
        $this->assertTrue($ten->fresh()->is_active, 'The default rate is always active.');
    }

    public function test_used_or_default_rates_can_not_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $used = VatRate::where('rate', 10)->firstOrFail();
        Product::factory()->create(['vat_rate_id' => $used->id]);
        $unused = VatRate::where('nature', 'N6.9')->firstOrFail();

        $this->actingAs($admin)->delete("/catalog/settings/vat-rates/{$used->id}")->assertRedirect();
        $this->actingAs($admin)->delete('/catalog/settings/vat-rates/'.VatRate::default()->id)->assertRedirect();
        $this->actingAs($admin)->delete("/catalog/settings/vat-rates/{$unused->id}")->assertRedirect();

        $this->assertModelExists($used);
        $this->assertModelExists(VatRate::default());
        $this->assertModelMissing($unused);
    }
}
