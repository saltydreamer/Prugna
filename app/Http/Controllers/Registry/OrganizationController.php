<?php

namespace App\Http\Controllers\Registry;

use App\Enums\LocationType;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registry\OrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'archivedCount' => Organization::onlyTrashed()->count(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Organization::class);

        return Inertia::render('registry/organizations/Create', [
            'types' => OrganizationType::options(),
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

    public function show(Organization $organization): Response
    {
        $this->authorize('view', $organization);

        $organization->load([
            'creator',
            'locations' => fn ($query) => $query->withCount('contacts')->orderByDesc('is_primary')->orderBy('name'),
            'contacts' => fn ($query) => $query->with('location')->orderByDesc('is_primary')->orderBy('last_name')->orderBy('first_name'),
        ]);

        return Inertia::render('registry/organizations/Show', [
            'organization' => OrganizationResource::make($organization)->resolve(),
            'locationTypes' => LocationType::options(),
        ]);
    }

    public function edit(Organization $organization): Response
    {
        $this->authorize('update', $organization);

        return Inertia::render('registry/organizations/Edit', [
            'organization' => OrganizationResource::make($organization)->resolve(),
            'types' => OrganizationType::options(),
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
}
