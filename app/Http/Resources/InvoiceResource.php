<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
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
            'display_number' => $this->displayNumber(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'sdi_status' => $this->sdi_status?->value,
            'sdi_status_label' => $this->sdi_status?->label(),
            'sdi_status_color' => $this->sdi_status?->color(),
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'issue_date' => $this->issue_date?->toDateString(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_date' => $this->due_date?->toDateString(),
            'organization_id' => $this->organization_id,
            'contact_id' => $this->contact_id,
            'owner_id' => $this->owner_id,
            'customer' => $this->customer,
            'quote_id' => $this->quote_id,
            'order_id' => $this->order_id,
            'related_invoice_id' => $this->related_invoice_id,
            'quote' => $this->whenLoaded('quote', fn () => $this->quote?->only(['id', 'number'])),
            'order' => $this->whenLoaded('order', fn () => $this->order?->only(['id', 'number'])),
            'related_invoice' => $this->whenLoaded('relatedInvoice', fn () => $this->relatedInvoice ? [
                'id' => $this->relatedInvoice->id,
                'number' => $this->relatedInvoice->displayNumber(),
                'issue_date' => $this->relatedInvoice->issue_date?->toDateString(),
            ] : null),
            'credit_notes' => $this->whenLoaded('creditNotes', fn () => $this->creditNotes->map(fn (Invoice $note) => [
                'id' => $note->id,
                'number' => $note->displayNumber(),
                'status' => $note->status->value,
                'total' => (float) $note->total,
            ])->values()->all()),
            'title' => $this->title,
            'customer_reference' => $this->customer_reference,
            'customer_reference_date' => $this->customer_reference_date?->toDateString(),
            'cig' => $this->cig,
            'cup' => $this->cup,
            'payment_term_id' => $this->payment_term_id,
            'payment_terms' => $this->payment_terms,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'internal_notes' => $this->internal_notes,
            'regime' => $this->regime,
            'split_payment' => $this->split_payment,
            'charge_stamp_duty' => $this->charge_stamp_duty,
            'apply_withholding' => $this->apply_withholding,
            'withholding_type' => $this->withholding_type,
            'withholding_rate' => (float) $this->withholding_rate,
            'withholding_reason' => $this->withholding_reason,
            'withholding_amount' => (float) $this->withholding_amount,
            'apply_pension' => $this->apply_pension,
            'pension_type' => $this->pension_type,
            'pension_rate' => (float) $this->pension_rate,
            'pension_vat_rate' => (float) $this->pension_vat_rate,
            'pension_vat_nature' => $this->pension_vat_nature,
            'pension_withholding' => $this->pension_withholding,
            'pension_amount' => (float) $this->pension_amount,
            'stamp_duty_amount' => (float) $this->stamp_duty_amount,
            'subtotal' => (float) $this->subtotal,
            'vat_total' => (float) $this->vat_total,
            'total' => (float) $this->total,
            'amount_due' => (float) $this->amount_due,
            // Collection: only with the credit notes loaded (the list loads them), to avoid a query per invoice.
            $this->mergeWhen($this->relationLoaded('creditNotes'), fn () => [
                'paid_amount' => (float) $this->paid_amount,
                'paid_at' => $this->paid_at?->toDateString(),
                'outstanding' => $this->outstanding(),
                'payment_status' => $this->paymentStatus()?->value,
                'payment_status_label' => $this->paymentStatus()?->label(),
                'payment_status_color' => $this->paymentStatus()?->color(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
                'email' => $this->contact->email,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(DocumentLineData::from(...))->values()->all()),
            'can' => $this->when($request->user() !== null, fn () => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
                'issue' => $request->user()->can('issue', $this->resource),
                'credit_note' => $request->user()->can('creditNote', $this->resource),
            ]),
        ];
    }
}
