<?php

namespace App\Http\Resources;

use App\Models\Intervention;
use App\Models\InterventionMaterial;
use App\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Intervention
 */
class InterventionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->only(['id', 'name'])),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name, 'address' => $this->location->full_address] : null),
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? ['id' => $this->contact->id, 'name' => $this->contact->full_name, 'email' => $this->contact->email, 'phone' => $this->contact->mobile ?: $this->contact->phone] : null),
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order', fn () => $this->order?->only(['id', 'number', 'title'])),
            'split_from' => $this->whenLoaded('splitFrom', fn () => $this->splitFrom?->only(['id', 'number'])),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'mode' => $this->mode->value,
            'mode_label' => $this->mode->label(),
            'mode_icon' => $this->mode->icon(),
            'technician_id' => $this->technician_id,
            'technician' => $this->whenLoaded('technician', fn () => $this->technician?->name),
            'started_at' => $this->started_at->toIso8601String(),
            'ends_at' => $this->endsAt()?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'billable_minutes' => $this->billableMinutes(),
            'description' => $this->description,
            'internal_notes' => $this->internal_notes,
            'billable' => $this->billable,
            'callout' => $this->callout,
            'km' => (float) $this->km,
            'travel_minutes' => $this->travel_minutes,
            'billable_travel_minutes' => $this->billableTravelMinutes(),
            'materials' => $this->whenLoaded('materials', fn () => $this->materials->map(fn (InterventionMaterial $material) => [
                'id' => $material->id,
                'product_id' => $material->product_id,
                'code' => $material->code,
                'description' => $material->description,
                'unit' => $material->unit,
                'quantity' => (float) $material->quantity,
                'unit_price' => (float) $material->unit_price,
                'vat_rate_id' => $material->vat_rate_id,
                'total' => $material->total(),
            ])->values()->all()),
            'invoices' => $this->whenLoaded('invoiceLines', fn () => $this->invoiceLines->map(fn (InvoiceLine $line) => $line->invoice)->filter()->unique('id')->map(fn ($invoice) => [
                'id' => $invoice->id,
                'label' => $invoice->displayNumber(),
                'status' => $invoice->status->value,
            ])->values()->all()),
            'contract_id' => $this->contract_id,
            'contract_minutes' => $this->contract_minutes,
            'invoiceable_minutes' => $this->invoiceableMinutes(),
            'skip_contract' => $this->skip_contract,
            'contract' => $this->whenLoaded('contract', fn () => $this->contract ? [
                'id' => $this->contract->id,
                'number' => $this->contract->number,
                'title' => $this->contract->title,
                'remaining_minutes' => $this->contract->remainingMinutes(),
            ] : null),
            'signed' => $this->isSigned(),
            'signed_by_name' => $this->signed_by_name,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signature_url' => $this->signature_path ? route('interventions.signature', ['intervention' => $this->id, 'v' => $this->signed_at?->timestamp]) : null,
            'report_sent_at' => $this->report_sent_at?->toIso8601String(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'invoiced_outside_at' => $this->invoiced_outside_at?->toIso8601String(),
            'running' => $this->isRunning(),
            'editable' => $this->isEditable(),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'sign' => $request->user()?->can('sign', $this->resource) ?? false,
                'unsign' => $request->user()?->can('unsign', $this->resource) ?? false,
                'send' => $request->user()?->can('send', $this->resource) ?? false,
                'unmark_invoiced' => $request->user()?->can('unmarkInvoiced', $this->resource) ?? false,
            ],
        ];
    }
}
