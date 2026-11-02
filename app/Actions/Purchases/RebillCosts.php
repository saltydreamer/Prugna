<?php

namespace App\Actions\Purchases;

use App\Enums\QuoteLineType;
use App\Models\ExpenseLine;
use App\Models\Order;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Costs to charge back to a customer ("ribaltamento costi") - supplier order lines and expense lines -
 * and the invoice lines that do it.
 */
class RebillCosts
{
    /**
     * Costs marked "rebill" not yet in an invoice: lines of committed supplier orders and of expenses.
     *
     * @return Collection<int, PurchaseOrderLine|ExpenseLine>
     */
    public function pending(int $customerId, ?Order $order = null): Collection
    {
        $purchaseLines = PurchaseOrderLine::query()
            ->toRebill()
            ->whereHas('purchaseOrder', fn (Builder $query) => $query
                ->where('customer_id', $customerId)
                ->when($order, fn (Builder $query) => $query->where('order_id', $order->id)))
            ->with('purchaseOrder:id,number,order_date,supplier')
            ->orderBy('purchase_order_id')
            ->orderBy('position')
            ->get();

        $expenseLines = ExpenseLine::query()
            ->toRebill()
            ->whereHas('expense', fn (Builder $query) => $query
                ->where('customer_id', $customerId)
                ->when($order, fn (Builder $query) => $query->where('order_id', $order->id)))
            ->with('expense:id,type,number,document_date,supplier')
            ->orderBy('expense_id')
            ->orderBy('position')
            ->get();

        return $purchaseLines->toBase()->concat($expenseLines)->values();
    }

    /**
     * The invoice line charging back a cost: net unit cost plus markup, same VAT rate.
     *
     * @return array<string, mixed>
     */
    public function invoiceLine(PurchaseOrderLine|ExpenseLine $line): array
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
            ...self::link($line),
        ];
    }

    /**
     * For the invoice editor dialog.
     *
     * @return array<string, mixed>
     */
    public function option(PurchaseOrderLine|ExpenseLine $line): array
    {
        $isExpense = $line instanceof ExpenseLine;
        $document = $isExpense ? $line->expense : $line->purchaseOrder;

        return [
            ...$this->invoiceLine($line),
            'type' => QuoteLineType::Item->value,
            'key' => self::key($line),
            'cost' => (float) $line->total,
            'markup_percent' => (float) $line->markup_percent,
            'amount' => $line->rebillAmount(),
            'source' => [
                'label' => $isExpense
                    ? trim($document->type->label().' '.($document->number ?? ''))
                    : __('Ordine :number', ['number' => $document->number]),
                'supplier' => $document->supplier['name'] ?? null,
                'date' => ($isExpense ? $document->document_date : $document->order_date)->toDateString(),
            ],
        ];
    }

    /**
     * Identifier of a cost across both sources ("po-12", "ex-7").
     */
    public static function key(PurchaseOrderLine|ExpenseLine $line): string
    {
        return ($line instanceof ExpenseLine ? 'ex-' : 'po-').$line->id;
    }

    /**
     * @return array{purchase_order_line_id: int|null, expense_line_id: int|null}
     */
    private static function link(PurchaseOrderLine|ExpenseLine $line): array
    {
        return [
            'purchase_order_line_id' => $line instanceof PurchaseOrderLine ? $line->id : null,
            'expense_line_id' => $line instanceof ExpenseLine ? $line->id : null,
        ];
    }
}
