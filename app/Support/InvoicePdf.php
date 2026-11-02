<?php

namespace App\Support;

use App\Actions\Invoices\InvoiceCalculator;
use App\Models\Invoice;
use App\Support\Fiscal\FiscalCodes;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Courtesy copy of an invoice (resources/views/pdf/invoice.blade.php).
 * The legally valid document is the FatturaPA XML sent through the SDI.
 */
class InvoicePdf extends CompanyPdf
{
    public function __construct(Settings $settings, private InvoiceCalculator $calculator)
    {
        parent::__construct($settings);
    }

    public function make(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing(['lines', 'contact', 'relatedInvoice', 'order', 'quote']);

        return $this->render('pdf.invoice', [
            'invoice' => $invoice,
            'lines' => $invoice->lines,
            'totals' => $this->calculator->calculate($invoice, $invoice->lines),
            'legalReferences' => $this->legalReferences($invoice->lines),
            'pensionFund' => FiscalCodes::PENSION_FUNDS[$invoice->pension_type] ?? null,
            'isFlatRate' => in_array($invoice->regime, FiscalCodes::VAT_FREE_REGIMES, true),
        ]);
    }

    public function filename(Invoice $invoice): string
    {
        $number = $invoice->number ?? 'bozza-'.$invoice->id;

        return str_replace(' ', '-', $invoice->type->label())."-{$number}.pdf";
    }
}
