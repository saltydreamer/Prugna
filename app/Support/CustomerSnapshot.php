<?php

namespace App\Support;

use App\Enums\OrganizationType;
use App\Models\Organization;

/**
 * Customer data frozen on documents (quotes, orders, invoices).
 */
class CustomerSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Organization $organization): array
    {
        $location = $organization->locations()->where('is_primary', true)->first();

        return [
            'name' => $organization->name,
            'vat_number' => $organization->vat_number,
            'tax_code' => $organization->tax_code,
            'sdi_code' => $organization->sdi_code,
            'pec' => $organization->pec,
            'email' => $organization->email,
            'is_public_administration' => $organization->type === OrganizationType::PublicBody,
            'address' => $location?->address,
            'postal_code' => $location?->postal_code,
            'city' => $location?->city,
            'province' => $location?->province,
            'country' => $location?->country,
            'city_line' => $location ? trim(implode(' ', array_filter([$location->postal_code, $location->city, $location->province ? "({$location->province})" : null]))) : null,
        ];
    }
}
