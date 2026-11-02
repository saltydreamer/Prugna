<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'vat_number' => $this->vat_number,
            'tax_code' => $this->tax_code,
            'sdi_code' => $this->sdi_code,
            'split_payment' => $this->split_payment,
            'payment_term_id' => $this->payment_term_id,
            'payment_term' => $this->whenLoaded('paymentTerm', fn () => $this->paymentTerm?->only(['id', 'name'])),
            'email' => $this->email,
            'pec' => $this->pec,
            'phone' => $this->phone,
            'website' => $this->website,
            'notes' => $this->notes,
            'archived_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'locations_count' => $this->whenCounted('locations'),
            'contacts_count' => $this->whenCounted('contacts'),
            // Nested resources are resolved to plain arrays: Inertia would otherwise wrap them in "data".
            'primary_location' => $this->whenLoaded('primaryLocation', fn () => $this->primaryLocation ? LocationResource::make($this->primaryLocation)->resolve($request) : null),
            'primary_contact' => $this->whenLoaded('primaryContact', fn () => $this->primaryContact ? ContactResource::make($this->primaryContact)->resolve($request) : null),
            'locations' => $this->whenLoaded('locations', fn () => LocationResource::collection($this->locations)->resolve($request)),
            'contacts' => $this->whenLoaded('contacts', fn () => ContactResource::collection($this->contacts)->resolve($request)),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'can' => $this->when($request->user() !== null, fn () => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
                'restore' => $request->user()->can('restore', $this->resource),
            ]),
        ];
    }
}
