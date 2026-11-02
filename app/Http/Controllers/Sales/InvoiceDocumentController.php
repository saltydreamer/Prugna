<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\FatturaPa\FatturaPaXml;
use App\Support\InvoicePdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF (courtesy copy) and FatturaPA XML of an invoice.
 */
class InvoiceDocumentController extends Controller
{
    public function pdf(Request $request, Invoice $invoice, InvoicePdf $pdf): Response
    {
        $this->authorize('view', $invoice);

        $document = $pdf->make($invoice);

        return $request->boolean('download')
            ? $document->download($pdf->filename($invoice))
            : $document->stream($pdf->filename($invoice));
    }

    public function xml(Invoice $invoice, FatturaPaXml $xml): Response
    {
        $this->authorize('view', $invoice);
        abort_if($invoice->isDraft(), 409, __("L'XML si genera dopo l'emissione della fattura."));

        return response($xml->make($invoice), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$xml->filename($invoice).'"',
        ]);
    }
}
