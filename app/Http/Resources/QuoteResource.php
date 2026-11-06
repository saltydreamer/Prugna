<?php

namespace App\Http\Resources;

use App\Actions\Quotes\QuoteTotals;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
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
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'status_icon' => $this->status->icon(),
            'organization_id' => $this->organization_id,
            'contact_id' => $this->contact_id,
            'owner_id' => $this->owner_id,
            'customer' => $this->customer,
            'title' => $this->title,
            'issue_date' => $this->issue_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'is_expiring' => $this->isOpen() && $this->valid_until->diffInDays(today(), true) <= 3,
            'payment_term_id' => $this->payment_term_id,
            'payment_terms' => $this->payment_terms,
            'intro' => $this->intro,
            'terms' => $this->terms,
            'internal_notes' => $this->internal_notes,
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'total' => (float) $this->total,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'viewed_at' => $this->viewed_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_name' => $this->decision_name,
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
                'email' => $this->contact->email,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (QuoteLine $line) => [
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
                'is_optional' => $line->is_optional,
                'is_selected' => $line->is_selected,
                'package_hours' => $line->package_hours !== null ? (float) $line->package_hours : null,
                'package_months' => $line->package_months,
                'total' => (float) $line->total,
            ])->values()->all()),
            'totals' => $this->whenLoaded('lines', fn () => QuoteTotals::summarize($this->lines)),
            'can' => $this->when($request->user() !== null, fn () => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ]),
        ];
    }
}
