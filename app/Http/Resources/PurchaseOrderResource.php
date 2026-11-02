<?php

namespace App\Http\Resources;

use App\Actions\Documents\DocumentTotals;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOpen = in_array($this->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Confirmed], true);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'status_icon' => $this->status->icon(),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier,
            'contact_id' => $this->contact_id,
            'owner_id' => $this->owner_id,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['id', 'name'])),
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order', fn () => $this->order?->only(['id', 'number', 'title'])),
            'title' => $this->title,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'is_late' => $isOpen && $this->expected_date?->isPast() && ! $this->expected_date->isToday(),
            'supplier_reference' => $this->supplier_reference,
            'payment_term_id' => $this->payment_term_id,
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'internal_notes' => $this->internal_notes,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'total' => (float) $this->total,
            'created_at' => $this->created_at?->toIso8601String(),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
                'email' => $this->contact->email,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (PurchaseOrderLine $line) => [
                'id' => $line->id,
                'type' => $line->type->value,
                'product_id' => $line->product_id,
                'code' => $line->code,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'discount_percent' => (float) $line->discount_percent,
                'vat_rate_id' => $line->vat_rate_id,
                'vat_rate' => (float) $line->vat_rate,
                'vat_nature' => $line->vat_nature,
                'total' => (float) $line->total,
                'rebill' => $line->rebill,
                'markup_percent' => (float) $line->markup_percent,
                'rebill_amount' => round((float) $line->quantity * $line->rebillUnitPrice(), 2),
                'rebilled_in' => $line->relationLoaded('rebilledBy') && ($invoice = $line->rebilledBy->first()?->invoice)
                    ? ['id' => $invoice->id, 'number' => $invoice->displayNumber(), 'is_draft' => $invoice->isDraft()]
                    : null,
            ])->values()->all()),
            'totals' => $this->whenLoaded('lines', fn () => DocumentTotals::summarize($this->lines)),
            'can' => $this->when($request->user() !== null, fn () => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
                'edit_lines' => $request->user()->can('update', $this->resource) && $this->resource->isEditable(),
            ]),
        ];
    }
}
