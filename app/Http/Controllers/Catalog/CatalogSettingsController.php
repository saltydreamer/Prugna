<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductCategoryRequest;
use App\Http\Requests\Catalog\VatRateRequest;
use App\Http\Resources\VatRateResource;
use App\Models\ProductCategory;
use App\Models\VatRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product categories and VAT rates.
 */
class CatalogSettingsController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Permission::CatalogSettings->value);

        return Inertia::render('catalog/Settings', [
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
            'vatRates' => VatRateResource::collection(VatRate::query()->withCount('products')->ordered()->get())->resolve(),
            'natures' => collect(VatRate::NATURES)->map(fn (string $title, string $value) => ['value' => $value, 'title' => $title])->values(),
            'colors' => ProductCategory::COLORS,
        ]);
    }

    public function storeCategory(ProductCategoryRequest $request): RedirectResponse
    {
        ProductCategory::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria creata.')]);

        return back();
    }

    public function updateCategory(ProductCategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria aggiornata.')]);

        return back();
    }

    /**
     * Products of a deleted category stay in the catalog, without category.
     */
    public function destroyCategory(ProductCategory $category): RedirectResponse
    {
        Gate::authorize(Permission::CatalogSettings->value);

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria eliminata.')]);

        return back();
    }

    public function storeVatRate(VatRateRequest $request): RedirectResponse
    {
        DB::transaction(fn () => $this->saveVatRate(new VatRate, $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Aliquota creata.')]);

        return back();
    }

    public function updateVatRate(VatRateRequest $request, VatRate $vatRate): RedirectResponse
    {
        DB::transaction(fn () => $this->saveVatRate($vatRate, $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Aliquota aggiornata.')]);

        return back();
    }

    /**
     * Rates used by products can only be deactivated.
     */
    public function destroyVatRate(VatRate $vatRate): RedirectResponse
    {
        Gate::authorize(Permission::CatalogSettings->value);

        if ($vatRate->products()->exists() || $vatRate->is_default) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __("L'aliquota è predefinita o usata da alcuni articoli: puoi solo disattivarla.")]);

            return back();
        }

        $vatRate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Aliquota eliminata.')]);

        return back();
    }

    /**
     * Only one default rate; the default one can't be inactive.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveVatRate(VatRate $vatRate, array $data): void
    {
        $vatRate->fill([...$data, 'nature' => $data['nature'] ?? null, 'sort' => $data['sort'] ?? 0]);

        if ($vatRate->is_default) {
            $vatRate->is_active = true;
        }

        $vatRate->save();

        if ($vatRate->is_default) {
            VatRate::query()->whereKeyNot($vatRate->id)->update(['is_default' => false]);
        }
    }
}
