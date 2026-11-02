<?php

namespace App\Http\Resources;

use App\Actions\Documents\DocumentTotals;
use App\Models\Expense;
use App\Models\ExpenseLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
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
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier,
            'number' => $this->number,
            'document_date' => $this->document_date?->toDateString(),
            'title' => $this->title,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['id', 'name'])),
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order', fn () => $this->order?->only(['id', 'number', 'title'])),
            'payment_method' => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'due_date' => $this->due_date?->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),
            'is_paid' => $this->isPaid(),
            'is_overdue' => $this->isOverdue(),
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'total' => (float) $this->total,
            'has_attachment' => $this->attachment_path !== null,
            'attachment_name' => $this->attachment_name,
            'attachment_mime' => $this->attachment_mime,
            'source' => $this->source,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            'ai' => $this->whenLoaded('aiExtraction', fn () => $this->aiExtraction ? [
                'model' => $this->aiExtraction->model,
                'cost' => $this->aiExtraction->cost !== null ? (float) $this->aiExtraction->cost : null,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (ExpenseLine $line) => [
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
                'rebill_amount' => $line->rebillAmount(),
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
