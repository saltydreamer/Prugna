<?php

namespace App\Http\Controllers\Registry;

use App\Enums\LocationType;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registry\OrganizationRequest;
use App\Http\Requests\Registry\QuickOrganizationRequest;
use App\Http\Resources\CustomerPriceResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Product;
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

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Organization::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(OrganizationType::class)],
            'role' => ['nullable', Rule::in(['customer', 'supplier'])],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $organizations = Organization::query()
            ->with(['primaryLocation', 'primaryContact'])
            ->withCount(['locations', 'contacts'])
            ->when(($filters['status'] ?? 'active') === 'archived', fn ($query) => $query->onlyTrashed())
            ->search($filters['search'] ?? null)
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where("is_{$role}", true))
            ->orderBy($filters['sort_by'] ?? 'name', $filters['sort_dir'] ?? 'asc')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('registry/organizations/Index', [
            'organizations' => OrganizationResource::collection($organizations),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? null,
                'role' => $filters['role'] ?? null,
                'status' => $filters['status'] ?? 'active',
                'sort_by' => $filters['sort_by'] ?? 'name',
                'sort_dir' => $filters['sort_dir'] ?? 'asc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'types' => OrganizationType::options(),
            'archivedCount' => Organization::onlyTrashed()->count(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Organization::class);

        return Inertia::render('registry/organizations/Create', [
            'types' => OrganizationType::options(),
            'paymentTerms' => $this->paymentTermOptions(),
        ]);
    }

    public function store(OrganizationRequest $request): RedirectResponse
    {
        $organization = new Organization($request->validated());
        $organization->created_by = $request->user()->id;
        $organization->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organizzazione creata. Aggiungi ora sedi e contatti.')]);

        return to_route('registry.organizations.show', $organization);
    }

    /**
     * Customer created from another page (e.g. the quote editor), with optional
     * registered office and contact. The new organization is returned as flash data.
     */
    public function quickStore(QuickOrganizationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $organization = DB::transaction(function () use ($data, $request): Organization {
            $organization = new Organization(Arr::except($data, ['location', 'contact']));
            $organization->created_by = $request->user()->id;
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

        $canSeePrices = $request->user()->can('viewAny', Product::class);

        return Inertia::render('registry/organizations/Show', [
            'organization' => OrganizationResource::make($organization)->resolve(),
            'locationTypes' => LocationType::options(),
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
            'types' => OrganizationType::options(),
            'paymentTerms' => $this->paymentTermOptions($organization->payment_term_id),
        ]);
    }

    public function update(OrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $organization->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organizzazione aggiornata.')]);

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

        return to_route('registry.organizations.index');
    }

    public function restore(Organization $organization): RedirectResponse
    {
        $this->authorize('restore', $organization);

        $organization->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name ripristinata.', ['name' => $organization->name])]);

        return to_route('registry.organizations.show', $organization);
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
