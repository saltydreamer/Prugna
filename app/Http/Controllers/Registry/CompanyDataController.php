<?php

namespace App\Http\Controllers\Registry;

use App\Actions\Registry\CompanyRegistryData;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Sdi\OpenapiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Customers and suppliers from the Registro Imprese (Openapi "Company"): the data for a new one,
 * and the changes to apply to an existing one.
 */
class CompanyDataController extends Controller
{
    /**
     * New customer or supplier: the registry data for the form, and the organization that already has them.
     */
    public function lookup(Request $request, CompanyRegistryData $registry): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'code' => ['required', 'string', 'max:32'],
        ], [], ['code' => 'partita IVA o codice fiscale']);

        $this->authorize('create', [Organization::class, OrganizationRole::from($data['role'])]);

        try {
            $company = $registry->lookup(CompanyRegistryData::code($data['code']));
        } catch (OpenapiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $existing = $company['vat_number'] || $company['tax_code']
            ? Organization::withTrashed()->where(function (Builder $query) use ($company): void {
                $query->when($company['vat_number'], fn (Builder $query, string $vat) => $query->orWhere('vat_number', $vat))
                    ->when($company['tax_code'], fn (Builder $query, string $taxCode) => $query->orWhere('tax_code', $taxCode));
            })->first()
            : null;

        return response()->json([
            'company' => $company,
            'existing' => $existing ? ['id' => $existing->id, 'name' => $existing->name, 'role' => $existing->role->label()] : null,
        ]);
    }

    /**
     * What the registry says about an organization and what differs from its data.
     */
    public function show(Organization $organization, CompanyRegistryData $registry): JsonResponse
    {
        $this->authorize('update', $organization);

        try {
            return response()->json($registry->compare($organization));
        } catch (OpenapiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, Organization $organization, CompanyRegistryData $registry): RedirectResponse
    {
        $this->authorize('update', $organization);

        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(array_keys(CompanyRegistryData::FIELDS))],
        ], [], ['fields' => 'dati da aggiornare']);

        try {
            $updated = $registry->apply($organization, $data['fields']);
        } catch (OpenapiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', $updated === []
            ? ['type' => 'info', 'message' => __('I dati coincidono già con il Registro Imprese.')]
            : ['type' => 'success', 'message' => __('Aggiornati dal Registro Imprese: :fields.', ['fields' => collect($updated)
                // "Codice fiscale" → "codice fiscale", acronyms as they are ("PEC").
                ->map(fn (string $label) => preg_match('/^\p{Lu}\p{Ll}/u', $label) ? mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1) : $label)
                ->implode(', ')])]);

        return back();
    }
}
