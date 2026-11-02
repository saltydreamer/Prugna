<?php

namespace App\Http\Resources;

use App\Actions\Documents\DocumentTotals;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOpen = in_array($this->status, [OrderStatus::Open, OrderStatus::InProgress], true);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'status_icon' => $this->status->icon(),
            'quote_id' => $this->quote_id,
            'quote' => $this->whenLoaded('quote', fn () => $this->quote?->only(['id', 'number'])),
            'organization_id' => $this->organization_id,
            'contact_id' => $this->contact_id,
            'owner_id' => $this->owner_id,
            'customer' => $this->customer,
            'title' => $this->title,
            'order_date' => $this->order_date?->toDateString(),
            'delivery_date' => $this->delivery_date?->toDateString(),
            'is_late' => $isOpen && $this->delivery_date?->isPast() && ! $this->delivery_date->isToday(),
            'customer_reference' => $this->customer_reference,
            'customer_reference_date' => $this->customer_reference_date?->toDateString(),
            'payment_term_id' => $this->payment_term_id,
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'internal_notes' => $this->internal_notes,
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'total' => (float) $this->total,
            'invoiced_percent' => $this->whenHas('invoiced_amount', fn () => (float) $this->subtotal > 0 ? round(min(100, (float) $this->invoiced_amount / (float) $this->subtotal * 100), 1) : 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
                'email' => $this->contact->email,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(DocumentLineData::from(...))->values()->all()),
            'totals' => $this->whenLoaded('lines', fn () => DocumentTotals::summarize($this->lines)),
            'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->sortBy('id')->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->displayNumber(),
                'type' => $invoice->type->value,
                'type_label' => $invoice->type->label(),
                'status' => $invoice->status->value,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'subtotal' => (float) $invoice->subtotal,
                'total' => (float) $invoice->total,
            ])->values()->all()),
            'can' => $this->when($request->user() !== null, fn () => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
                'edit_lines' => $request->user()->can('update', $this->resource) && $this->resource->isEditable(),
            ]),
        ];
    }
}
