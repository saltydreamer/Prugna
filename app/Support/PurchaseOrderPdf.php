<?php

namespace App\Support;

use App\Actions\Documents\DocumentTotals;
use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Order to a supplier (resources/views/pdf/purchase-order.blade.php). Markups and customers are internal: never printed.
 */
class PurchaseOrderPdf extends CompanyPdf
{
    public function make(PurchaseOrder $purchaseOrder): DomPdf
    {
        $purchaseOrder->loadMissing(['lines', 'contact', 'owner']);

        return $this->render('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder,
            'lines' => $purchaseOrder->lines,
            'totals' => DocumentTotals::summarize($purchaseOrder->lines),
            'legalReferences' => $this->legalReferences($purchaseOrder->lines),
        ]);
    }

    public function filename(PurchaseOrder $purchaseOrder): string
    {
        return "Ordine-{$purchaseOrder->number}.pdf";
    }
}
