<?php

namespace App\Http\Resources;

use App\Models\InvoiceLine;
use App\Models\OrderLine;

/**
 * Serializes the lines of orders and invoices for the frontend.
 */
class DocumentLineData
{
    /**
     * @return array<string, mixed>
     */
    public static function from(OrderLine|InvoiceLine $line): array
    {
        $data = [
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
        ];

        if ($line instanceof OrderLine) {
            return [
                ...$data,
                'quantity_invoiced' => (float) $line->quantity_invoiced,
                'quantity_remaining' => $line->remainingQuantity(),
                'package_hours' => $line->package_hours !== null ? (float) $line->package_hours : null,
                'package_months' => $line->package_months,
            ];
        }

        return [
            ...$data,
            'order_line_id' => $line->order_line_id,
            'system' => $line->system,
            'deducts_invoice_id' => $line->deducts_invoice_id,
            // Costs charged back by the line ("po-12", "ex-7").
            'costs' => $line->relationLoaded('costLinks') ? $line->costLinks->map(fn ($link) => $link->key())->values()->all() : [],
            // Interventions billed by the line (ids).
            'interventions' => $line->relationLoaded('interventions') ? $line->interventions->pluck('id')->values()->all() : [],
        ];
    }
}
