<?php

namespace App\Http\Controllers\Registry;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registry\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Address book: contacts of every active organization.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Organization::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'primary' => ['nullable', 'boolean'],
            'role' => ['nullable', Rule::enum(OrganizationRole::class)],
            'sort_by' => ['nullable', Rule::in(['last_name', 'created_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        // Only the registries the user can see.
        $roles = collect(OrganizationRole::cases())
            ->filter(fn (OrganizationRole $role) => $request->user()->can($role->permission('view')->value))
            ->when($filters['role'] ?? null, fn ($roles, string $role) => $roles->filter(fn (OrganizationRole $item) => $item->value === $role))
            ->map->value
            ->values();

        $contacts = Contact::query()
            ->whereHas('organization', fn ($query) => $query->whereIn('role', $roles))
            ->with(['organization', 'location'])
            ->search($filters['search'] ?? null)
            ->when($request->boolean('primary'), fn ($query) => $query->where('is_primary', true))
            ->orderBy($filters['sort_by'] ?? 'last_name', $filters['sort_dir'] ?? 'asc')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('registry/contacts/Index', [
            'contacts' => ContactResource::collection($contacts),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'primary' => $request->boolean('primary'),
                'role' => $filters['role'] ?? null,
                'sort_by' => $filters['sort_by'] ?? 'last_name',
                'sort_dir' => $filters['sort_dir'] ?? 'asc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
        ]);
    }

    public function store(ContactRequest $request, Organization $organization): RedirectResponse
    {
        $contact = $organization->contacts()->create($request->validated());

        // createdContact lets the page that opened the dialog select the new contact.
        Inertia::flash([
            'toast' => ['type' => 'success', 'message' => __('Contatto aggiunto.')],
            'createdContact' => ['value' => $contact->id, 'title' => $contact->full_name, 'subtitle' => $contact->email],
        ]);

        return back();
    }

    public function update(ContactRequest $request, Organization $organization, Contact $contact): RedirectResponse
    {
        $contact->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contatto aggiornato.')]);

        return back();
    }

    public function destroy(Organization $organization, Contact $contact): RedirectResponse
    {
        $this->authorize('update', $organization);

        $contact->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contatto eliminato.')]);

        return back();
    }
}
