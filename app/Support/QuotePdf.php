<?php

namespace App\Support;

use App\Actions\Quotes\QuoteTotals;
use App\Models\Quote;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * PDF of a quote (resources/views/pdf/quote.blade.php).
 */
class QuotePdf extends CompanyPdf
{
    public function make(Quote $quote): DomPdf
    {
        $quote->loadMissing(['lines', 'contact', 'owner']);

        return $this->render('pdf.quote', [
            'quote' => $quote,
            'lines' => $quote->lines,
            'totals' => QuoteTotals::summarize($quote->lines),
            'legalReferences' => $this->legalReferences($quote->lines),
        ]);
    }

    public function filename(Quote $quote): string
    {
        return "Preventivo-{$quote->number}.pdf";
    }
}
