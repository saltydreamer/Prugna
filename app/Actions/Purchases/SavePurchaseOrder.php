<?php

namespace App\Actions\Purchases;

use App\Actions\Documents\DocumentTotals;
use App\Actions\Documents\SyncDocumentLines;
use App\Enums\PurchaseOrderStatus;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\PurchaseOrder;
use App\Support\CustomerSnapshot;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates supplier orders: numbering OF-2026-0001, supplier snapshot, lines at cost, totals.
 */
class SavePurchaseOrder
{
    public const PREFIX = 'OF';

    public function __construct(private SyncDocumentLines $syncLines) {}

    /**
     * @param  array<string, mixed>  $data  Header fields plus "lines".
     */
    public function handle(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        return DB::transaction(function () use ($purchaseOrder, $data, $lines): PurchaseOrder {
            $purchaseOrder->fill($data);

            $supplier = Organization::withTrashed()->findOrFail($purchaseOrder->supplier_id);
            $purchaseOrder->supplier = CustomerSnapshot::from($supplier);
            $purchaseOrder->payment_terms = $purchaseOrder->payment_term_id ? PaymentTerm::find($purchaseOrder->payment_term_id)?->name : null;

            if (! $purchaseOrder->exists) {
                $purchaseOrder->status = PurchaseOrderStatus::Draft;
                $purchaseOrder->forceFill(DocumentNumber::next(PurchaseOrder::class, self::PREFIX, $purchaseOrder->order_date->year));
            }

            $purchaseOrder->save();

            if ($lines !== null) {
                $this->syncLines->handle($purchaseOrder->lines(), array_map(
                    // Without a customer there is nobody to charge the cost to.
                    fn (array $line) => [...$line, 'rebill' => $purchaseOrder->customer_id ? (bool) ($line['rebill'] ?? false) : false, 'markup_percent' => (float) ($line['markup_percent'] ?? 0)],
                    $lines,
                ), ['rebill', 'markup_percent']);
            }

            $totals = DocumentTotals::summarize($purchaseOrder->lines()->get());
            $purchaseOrder->forceFill([
                'subtotal' => $totals['subtotal'],
                'vat_total' => $totals['vat_total'],
                'total' => $totals['total'],
            ])->save();

            return $purchaseOrder;
        });
    }
}
