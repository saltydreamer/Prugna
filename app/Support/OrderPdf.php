<?php

namespace App\Support;

use App\Actions\Documents\DocumentTotals;
use App\Models\Order;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Order confirmation ("Conferma d'ordine", resources/views/pdf/order.blade.php).
 */
class OrderPdf extends CompanyPdf
{
    public function make(Order $order): DomPdf
    {
        $order->loadMissing(['lines', 'contact', 'owner', 'quote']);

        return $this->render('pdf.order', [
            'order' => $order,
            'lines' => $order->lines,
            'totals' => DocumentTotals::summarize($order->lines),
            'legalReferences' => $this->legalReferences($order->lines),
        ]);
    }

    public function filename(Order $order): string
    {
        return "Conferma-ordine-{$order->number}.pdf";
    }
}
