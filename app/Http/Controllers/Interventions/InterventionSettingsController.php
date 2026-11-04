<?php

namespace App\Http\Controllers\Interventions;

use App\Enums\Permission;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Product;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Impostazioni interventi: categories with their hourly services, rounding and the services
 * billed for call-out, km and travel time. A missing service can be created on the fly from its price.
 */
class InterventionSettingsController extends Controller
{
    private const EXTRAS = [
        'callout' => ['name' => 'Diritto di uscita', 'unit' => UnitOfMeasure::Lump],
        'km' => ['name' => 'Rimborso chilometrico', 'unit' => UnitOfMeasure::Kilometer],
        'travel' => ['name' => 'Tempo di viaggio', 'unit' => UnitOfMeasure::Hour],
    ];

    public function __construct(private Settings $settings) {}

    public function index(): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        return Inertia::render('interventions/Settings', [
            'categories' => InterventionCategory::query()->with(['remoteProduct', 'onsiteProduct'])->withCount('interventions')->ordered()->get()
                ->map(fn (InterventionCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'remote_product_id' => $category->remote_product_id,
                    'onsite_product_id' => $category->onsite_product_id,
                    'remote_product' => $this->productSummary($category->remoteProduct),
                    'onsite_product' => $this->productSummary($category->onsiteProduct),
                    'is_active' => $category->is_active,
                    'sort' => $category->sort,
                    'interventions_count' => $category->interventions_count,
                ]),
            'settings' => [
                'rounding_minutes' => Intervention::rounding(),
                ...collect(array_keys(self::EXTRAS))->mapWithKeys(fn (string $extra) => [
                    "{$extra}_product_id" => $this->settings->get("interventions.{$extra}_product_id") ? (int) $this->settings->get("interventions.{$extra}_product_id") : null,
                ])->all(),
            ],
            'products' => Product::query()->active()->orderByRaw("CASE WHEN type = 'service' THEN 0 ELSE 1 END")->orderBy('name')->limit(500)->get()
                ->map(fn (Product $product) => [
                    'value' => $product->id,
                    'title' => $product->name,
                    'subtitle' => trim($product->code.' · '.number_format((float) $product->price, 2, ',', '.').' € / '.$product->unit->value),
                ]),
            'roundingOptions' => [
                ['value' => 1, 'title' => 'Nessun arrotondamento (al minuto)'],
                ['value' => 15, 'title' => 'Al quarto d\'ora'],
                ['value' => 30, 'title' => 'Alla mezz\'ora'],
                ['value' => 60, 'title' => 'All\'ora'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $rules = ['rounding_minutes' => ['required', 'integer', Rule::in([1, 15, 30, 60])]];

        foreach (array_keys(self::EXTRAS) as $extra) {
            $rules["{$extra}_product_id"] = ['nullable', 'integer', Rule::exists('products', 'id')];
            $rules["{$extra}_new_price"] = ['nullable', 'numeric', 'between:0,99999999'];
        }

        $data = $request->validate($rules, [], [
            'rounding_minutes' => 'arrotondamento',
            'callout_new_price' => 'prezzo del diritto di uscita',
            'km_new_price' => 'prezzo al km',
            'travel_new_price' => 'tariffa del viaggio',
        ]);

        DB::transaction(function () use ($data) {
            $values = ['interventions.rounding_minutes' => (int) $data['rounding_minutes']];

            foreach (self::EXTRAS as $extra => $definition) {
                $productId = $data["{$extra}_product_id"] ?? null;

                if (! $productId && isset($data["{$extra}_new_price"]) && $data["{$extra}_new_price"] !== '') {
                    $productId = $this->createService(__($definition['name']), $definition['unit'], (float) $data["{$extra}_new_price"])->id;
                }

                $values["interventions.{$extra}_product_id"] = $productId;
            }

            $this->settings->set($values);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni degli interventi salvate.')]);

        return back();
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        DB::transaction(fn () => InterventionCategory::create($this->categoryData($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria creata.')]);

        return back();
    }

    public function updateCategory(Request $request, InterventionCategory $category): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        DB::transaction(fn () => $category->update($this->categoryData($request, $category)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria salvata.')]);

        return back();
    }

    /**
     * Categories already used are only deactivated.
     */
    public function destroyCategory(InterventionCategory $category): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        if ($category->interventions()->exists()) {
            $category->update(['is_active' => false]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('La categoria è usata da alcuni interventi: è stata disattivata.')]);
        } else {
            $category->delete();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Categoria eliminata.')]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryData(Request $request, ?InterventionCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('intervention_categories', 'name')->ignore($category?->id)],
            'remote_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'onsite_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'remote_new_price' => ['nullable', 'numeric', 'between:0,99999999'],
            'onsite_new_price' => ['nullable', 'numeric', 'between:0,99999999'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
        ], [], [
            'name' => 'nome',
            'remote_new_price' => 'tariffa oraria da remoto',
            'onsite_new_price' => 'tariffa oraria on site',
        ]);

        foreach (['remote' => 'da remoto', 'onsite' => 'on site'] as $mode => $label) {
            if (empty($data["{$mode}_product_id"]) && isset($data["{$mode}_new_price"]) && $data["{$mode}_new_price"] !== '') {
                $data["{$mode}_product_id"] = $this->createService("{$data['name']} {$label}", UnitOfMeasure::Hour, (float) $data["{$mode}_new_price"])->id;
            }

            unset($data["{$mode}_new_price"]);
        }

        return [...$data, 'is_active' => $request->boolean('is_active', true), 'sort' => (int) ($data['sort'] ?? 0)];
    }

    private function createService(string $name, UnitOfMeasure $unit, float $price): Product
    {
        $vatRate = VatRate::default() ?? VatRate::query()->where('is_active', true)->firstOrFail();

        return Product::create([
            'type' => ProductType::Service,
            'code' => Product::nextCode(ProductType::Service),
            'name' => $name,
            'unit' => $unit,
            'price' => $price,
            'vat_rate_id' => $vatRate->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{id: int, name: string, price: float, unit: string}|null
     */
    private function productSummary(?Product $product): ?array
    {
        return $product ? ['id' => $product->id, 'name' => $product->name, 'price' => (float) $product->price, 'unit' => $product->unit->value] : null;
    }
}
