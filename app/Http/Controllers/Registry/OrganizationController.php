<?php

namespace App\Http\Controllers\Registry;

use App\Actions\Documents\OrganizationDraft;
use App\Enums\LocationType;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registry\OrganizationRequest;
use App\Http\Requests\Registry\QuickOrganizationRequest;
use App\Http\Resources\CustomerPriceResource;
use App\Http\Resources\OrganizationResource;
use App\Models\AiExtraction;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    private const SORTABLE = ['name', 'created_at', 'updated_at'];

    /**
     * Customers or suppliers list: the role comes from the route (registry.customers / registry.suppliers).
     */
    public function index(Request $request, string $role): Response
    {
        $role = OrganizationRole::from($role);
        $this->authorize('viewAny', [Organization::class, $role]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(OrganizationType::class)],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $organizations = Organization::query()
            ->where('role', $role->value)
            ->with(['primaryLocation', 'primaryContact'])
            ->withCount(['locations', 'contacts'])
            ->when(($filters['status'] ?? 'active') === 'archived', fn ($query) => $query->onlyTrashed())
            ->search($filters['search'] ?? null)
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->orderBy($filters['sort_by'] ?? 'name', $filters['sort_dir'] ?? 'asc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('registry/organizations/Index', [
            'organizations' => OrganizationResource::collection($organizations),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? null,
                'status' => $filters['status'] ?? 'active',
                'sort_by' => $filters['sort_by'] ?? 'name',
                'sort_dir' => $filters['sort_dir'] ?? 'asc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'types' => OrganizationType::options(),
            'role' => $this->roleData($role),
            'archivedCount' => Organization::onlyTrashed()->where('role', $role->value)->count(),
        ]);
    }

    public function create(Request $request, string $role, OrganizationDraft $draft): Response
    {
        $role = OrganizationRole::from($role);
        $this->authorize('create', [Organization::class, $role]);

        $extraction = $request->integer('extraction') ? AiExtraction::query()->where('kind', AiExtraction::KIND_ORGANIZATION)->find($request->integer('extraction')) : null;
        $prefill = $extraction?->succeeded() ? $draft->fromExtraction($extraction) : null;

        return Inertia::render('registry/organizations/Create', [
            'role' => $this->roleData($role),
            'types' => OrganizationType::options(),
            'paymentTerms' => $this->paymentTermOptions(),
            'defaults' => $prefill['defaults'] ?? null,
            'review' => $prefill['review'] ?? null,
            'canUseAi' => $request->user()->can(Permission::AiUse->value),
        ]);
    }

    /**
     * New customer or supplier, with optional registered office and contact person.
     */
    public function store(QuickOrganizationRequest $request): RedirectResponse
    {
        $organization = $this->createWithDetails($request->validated(), $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':role creato.', ['role' => $organization->role->label()])]);

        return to_route('registry.organizations.show', $organization);
    }

    /**
     * Customer created from another page (e.g. the quote editor), with optional
     * registered office and contact. The new organization is returned as flash data.
     */
    public function quickStore(QuickOrganizationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $organization = $this->createWithDetails($data, $request->user()->id);

        Inertia::flash([
            'toast' => ['type' => 'success', 'message' => __(':name aggiunto alle anagrafiche.', ['name' => $organization->name])],
            'createdOrganization' => [
                'value' => $organization->id,
                'title' => $organization->name,
                'subtitle' => $organization->vat_number ? "P.IVA {$organization->vat_number}" : null,
            ],
        ]);

        return back();
    }

    public function show(Request $request, Organization $organization): Response
    {
        $this->authorize('view', $organization);

        $organization->load([
            'creator',
            'paymentTerm',
            'locations' => fn ($query) => $query->withCount('contacts')->orderByDesc('is_primary')->orderBy('name'),
            'contacts' => fn ($query) => $query->with('location')->orderByDesc('is_primary')->orderBy('last_name')->orderBy('first_name'),
        ]);

        $isSupplier = $organization->isSupplier();
        $canSeePrices = ! $isSupplier && $request->user()->can('viewAny', Product::class);

        return Inertia::render('registry/organizations/Show', [
            'organization' => OrganizationResource::make($organization)->resolve(),
            'role' => $this->roleData($organization->role),
            'locationTypes' => LocationType::options(),
            'purchaseOrders' => $isSupplier && $request->user()->can('viewAny', PurchaseOrder::class)
                ? PurchaseOrder::query()->where('supplier_id', $organization->id)->latest('id')->limit(10)->get()
                    ->map(fn (PurchaseOrder $purchaseOrder) => [
                        'id' => $purchaseOrder->id,
                        'number' => $purchaseOrder->number,
                        'title' => $purchaseOrder->title,
                        'order_date' => $purchaseOrder->order_date->toDateString(),
                        'status_label' => $purchaseOrder->status->label(),
                        'status_color' => $purchaseOrder->status->color(),
                        'subtotal' => (float) $purchaseOrder->subtotal,
                    ])
                : null,
            'interventions' => ! $isSupplier && ($request->user()->can('viewAny', Intervention::class) || $request->user()->can(Permission::InvoicesManage->value))
                ? Intervention::summary(Intervention::query()->where('organization_id', $organization->id)
                    ->when(! $request->user()->can(Permission::InvoicesManage->value), fn ($query) => $query->visibleTo($request->user())))
                : null,
            'customerPrices' => $canSeePrices
                ? CustomerPriceResource::collection($organization->customerPrices()->with('product')->get()->sortBy('product.name')->values())->resolve()
                : null,
            // Loaded only when the "agreed price" dialog is opened.
            'productOptions' => Inertia::optional(fn () => Product::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'price', 'unit'])
                ->map(fn (Product $product) => [
                    'value' => $product->id,
                    'title' => "{$product->code} · {$product->name}",
                    'price' => (float) $product->price,
                ])),
        ]);
    }

    public function edit(Organization $organization): Response
    {
        $this->authorize('update', $organization);

        return Inertia::render('registry/organizations/Edit', [
            'organization' => OrganizationResource::make($organization)->resolve(),
            // The role can change only while no document refers to the organization.
            'canChangeRole' => ! $organization->hasDocuments(),
            'role' => $this->roleData($organization->role),
            'types' => OrganizationType::options(),
            'paymentTerms' => $this->paymentTermOptions($organization->payment_term_id),
        ]);
    }

    public function update(OrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $organization->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name aggiornato.', ['name' => $organization->name])]);

        return to_route('registry.organizations.show', $organization);
    }

    /**
     * Archive: the organization disappears from lists but its data is kept.
     */
    public function destroy(Organization $organization): RedirectResponse
    {
        $this->authorize('delete', $organization);

        $organization->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name archiviata.', ['name' => $organization->name])]);

        return to_route($organization->role->routePrefix().'.index');
    }

    public function restore(Organization $organization): RedirectResponse
    {
        $this->authorize('restore', $organization);

        $organization->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name ripristinata.', ['name' => $organization->name])]);

        return to_route('registry.organizations.show', $organization);
    }

    /**
     * @param  array<string, mixed>  $data  Organization fields plus optional "location" and "contact".
     */
    private function createWithDetails(array $data, int $userId): Organization
    {
        return DB::transaction(function () use ($data, $userId): Organization {
            $organization = new Organization(Arr::except($data, ['location', 'contact']));
            $organization->created_by = $userId;
            $organization->save();

            if (filled($data['location']['address'] ?? null)) {
                $organization->locations()->create([
                    ...Arr::only($data['location'], ['address', 'postal_code', 'city', 'province', 'country']),
                    'name' => __('Sede legale'),
                    'type' => LocationType::Registered,
                    'is_primary' => true,
                ]);
            }

            if (filled($data['contact']['first_name'] ?? null)) {
                $organization->contacts()->create([
                    ...Arr::only($data['contact'], ['first_name', 'last_name', 'job_title', 'email', 'phone']),
                    'is_primary' => true,
                ]);
            }

            return $organization;
        });
    }

    /**
     * @return array{value: string, label: string, plural: string, index_route: string, create_route: string}
     */
    private function roleData(OrganizationRole $role): array
    {
        return [
            'value' => $role->value,
            'label' => $role->label(),
            'plural' => $role->pluralLabel(),
            'index_route' => $role->routePrefix().'.index',
            'create_route' => $role->routePrefix().'.create',
        ];
    }

    /**
     * Active payment terms, plus the current one if it has been deactivated.
     *
     * @return list<array{value: int, title: string}>
     */
    private function paymentTermOptions(?int $currentId = null): array
    {
        return PaymentTerm::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($currentId, fn ($query) => $query->orWhere('id', $currentId)))
            ->ordered()
            ->get()
            ->map(fn (PaymentTerm $term) => ['value' => $term->id, 'title' => $term->name])
            ->all();
    }
}
