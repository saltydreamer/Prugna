<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\CustomerPriceResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VatRateResource;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    private const SORTABLE = ['code', 'name', 'price', 'updated_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ProductType::class)],
            'category' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'recurring' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $status = $filters['status'] ?? 'active';

        $products = Product::query()
            ->with(['category', 'vatRate'])
            ->withCount('customerPrices')
            ->search($filters['search'] ?? null)
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['category'] ?? null, fn ($query, int $category) => $query->where('category_id', $category))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($request->boolean('recurring'), fn ($query) => $query->whereNotNull('billing_period'))
            ->orderBy($filters['sort_by'] ?? 'name', $filters['sort_dir'] ?? 'asc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('catalog/products/Index', [
            'products' => ProductResource::collection($products),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? null,
                'category' => isset($filters['category']) ? (int) $filters['category'] : null,
                'status' => $status,
                'recurring' => $request->boolean('recurring'),
                'sort_by' => $filters['sort_by'] ?? 'name',
                'sort_dir' => $filters['sort_dir'] ?? 'asc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'types' => ProductType::options(),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('catalog/products/Create', [
            ...$this->formOptions(),
            'defaultVatRateId' => VatRate::default()?->id,
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['code'] ??= Product::nextCode(ProductType::from($data['type']));

        $product = Product::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code creato.', ['code' => $product->code])]);

        return to_route('catalog.products.show', $product);
    }

    public function show(Product $product): Response
    {
        $this->authorize('view', $product);

        $product->load(['category', 'vatRate'])->loadCount('customerPrices');

        $customerPrices = $product->customerPrices()
            ->with(['organization', 'product'])
            ->whereHas('organization')
            ->get()
            ->sortBy('organization.name')
            ->values();

        return Inertia::render('catalog/products/Show', [
            'product' => ProductResource::make($product)->resolve(),
            'customerPrices' => CustomerPriceResource::collection($customerPrices)->resolve(),
            // Loaded only when the "agreed price" dialog is opened.
            'organizationOptions' => Inertia::optional(fn () => Organization::query()
                ->customers()
                ->orderBy('name')
                ->get(['id', 'name', 'vat_number'])
                ->map(fn (Organization $organization) => [
                    'value' => $organization->id,
                    'title' => $organization->name,
                    'subtitle' => $organization->vat_number ? "P.IVA {$organization->vat_number}" : null,
                ])),
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('catalog/products/Edit', [
            'product' => ProductResource::make($product)->resolve(),
            ...$this->formOptions($product),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $data['code'] ??= $product->code;

        $product->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Articolo aggiornato.')]);

        return to_route('catalog.products.show', $product);
    }

    /**
     * Products will be referenced by documents: deactivate them instead when they have a history.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code eliminato.', ['code' => $product->code])]);

        return to_route('catalog.products.index');
    }

    /**
     * Copy of an existing product, ready to be edited.
     */
    public function duplicate(Product $product): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $copy = $product->replicate();
        $copy->code = Product::nextCode($product->type);
        $copy->name = __(':name (copia)', ['name' => $product->name]);
        $copy->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copia creata: modifica i dati e salva.')]);

        return to_route('catalog.products.edit', $copy);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Product $product = null): array
    {
        return [
            'types' => ProductType::options(),
            'units' => UnitOfMeasure::options(),
            'billingPeriods' => BillingPeriod::options(),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name', 'color']),
            // Active rates, plus the current one if it has been deactivated meanwhile.
            'vatRates' => VatRateResource::collection(
                VatRate::query()
                    ->where(fn ($query) => $query->where('is_active', true)->when($product, fn ($query) => $query->orWhere('id', $product->vat_rate_id)))
                    ->ordered()
                    ->get()
            )->resolve(),
            'nextCodes' => [
                ProductType::Product->value => Product::nextCode(ProductType::Product),
                ProductType::Service->value => Product::nextCode(ProductType::Service),
            ],
        ];
    }
}
