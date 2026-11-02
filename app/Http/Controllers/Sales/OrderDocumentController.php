<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Order confirmation PDF.
 */
class OrderDocumentController extends Controller
{
    public function pdf(Request $request, Order $order, OrderPdf $pdf): Response
    {
        $this->authorize('view', $order);

        $document = $pdf->make($order);

        return $request->boolean('download')
            ? $document->download($pdf->filename($order))
            : $document->stream($pdf->filename($order));
    }
}
