<?php

namespace App\Actions\Purchases;

use App\Enums\QuoteLineType;
use App\Models\Order;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Supplier costs to charge back to a customer ("ribaltamento costi") and the invoice lines that do it.
 */
class RebillCosts
{
    /**
     * Costs of committed supplier orders marked "rebill", not yet in an invoice.
     *
     * @return Collection<int, PurchaseOrderLine>
     */
    public function pending(int $customerId, ?Order $order = null): Collection
    {
        return PurchaseOrderLine::query()
            ->toRebill()
            ->whereHas('purchaseOrder', fn (Builder $query) => $query
                ->where('customer_id', $customerId)
                ->when($order, fn (Builder $query) => $query->where('order_id', $order->id)))
            ->with('purchaseOrder:id,number,order_date,supplier')
            ->orderBy('purchase_order_id')
            ->orderBy('position')
            ->get();
    }

    /**
     * The invoice line charging back a cost: net unit cost plus markup, same VAT rate.
     *
     * @return array<string, mixed>
     */
    public function invoiceLine(PurchaseOrderLine $line): array
    {
        return [
            'type' => QuoteLineType::Item,
            'product_id' => $line->product_id,
            'code' => $line->code,
            'description' => $line->description,
            'unit' => $line->unit,
            'quantity' => (float) $line->quantity,
            'unit_price' => $line->rebillUnitPrice(),
            'discount_percent' => 0,
            'vat_rate_id' => $line->vat_rate_id,
            'purchase_order_line_id' => $line->id,
        ];
    }

    /**
     * For the invoice editor dialog.
     *
     * @return array<string, mixed>
     */
    public function option(PurchaseOrderLine $line): array
    {
        return [
            ...$this->invoiceLine($line),
            'type' => QuoteLineType::Item->value,
            'cost' => (float) $line->total,
            'markup_percent' => (float) $line->markup_percent,
            'amount' => round((float) $line->quantity * $line->rebillUnitPrice(), 2),
            'purchase_order' => [
                'id' => $line->purchaseOrder->id,
                'number' => $line->purchaseOrder->number,
                'supplier' => $line->purchaseOrder->supplier['name'] ?? null,
                'order_date' => $line->purchaseOrder->order_date->toDateString(),
            ],
        ];
    }
}
