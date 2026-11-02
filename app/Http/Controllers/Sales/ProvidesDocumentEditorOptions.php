<?php

namespace App\Http\Controllers\Sales;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\UnitOfMeasure;
use App\Http\Resources\VatRateResource;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\VatRate;

/**
 * Options shared by the order and invoice editors.
 */
trait ProvidesDocumentEditorOptions
{
    /**
     * Customers or suppliers for a picker, including the current (possibly archived) one.
     *
     * @return list<array{value: int, title: string, subtitle: string|null}>
     */
    protected function organizationOptions(OrganizationRole $role, ?int $currentOrganizationId = null): array
    {
        return Organization::query()
            ->where('role', $role->value)
            ->when($currentOrganizationId, fn ($query) => $query->withTrashed()->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $currentOrganizationId)))
            ->orderBy('name')
            ->get(['id', 'name', 'vat_number'])
            ->map(fn (Organization $organization) => [
                'value' => $organization->id,
                'title' => $organization->name,
                'subtitle' => $organization->vat_number ? "P.IVA {$organization->vat_number}" : null,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function editorOptions(?int $currentOrganizationId = null): array
    {
        return [
            'organizations' => $this->organizationOptions(OrganizationRole::Customer, $currentOrganizationId),
            'vatRates' => VatRateResource::collection(VatRate::query()->where('is_active', true)->ordered()->get())->resolve(),
            'paymentTerms' => PaymentTerm::query()->active()->ordered()->get(['id', 'name'])
                ->map(fn (PaymentTerm $term) => ['value' => $term->id, 'title' => $term->name]),
            'units' => UnitOfMeasure::options(),
            'organizationTypes' => OrganizationType::options(),
            'owners' => User::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'title' => $user->name]),
        ];
    }
}
