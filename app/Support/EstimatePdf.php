<?php

namespace App\Support;

use App\Models\Estimate;
use App\Support\Estimates\EstimateCalculator;
use App\Support\Fiscal\FiscalCodes;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Report of an estimate for the customer (resources/views/pdf/estimate.blade.php): what is included,
 * financial plan by year, advantages. Never shows costs or markups.
 */
class EstimatePdf extends CompanyPdf
{
    public function make(Estimate $estimate): DomPdf
    {
        $estimate->loadMissing(['items', 'organization']);
        $totals = app(EstimateCalculator::class)->summarize($estimate);
        $years = [];

        for ($month = 1; $month <= $totals['months']; $month++) {
            $year = intdiv($month - 1, 12) + 1;
            $years[$year] ??= ['year' => $year, 'months' => 0, 'fees' => 0.0, 'upfront' => $year === 1 ? $totals['upfront'] : 0.0];
            $years[$year]['months']++;
            $years[$year]['fees'] += $totals['monthly'];
        }

        return $this->render('pdf.estimate', [
            'estimate' => $estimate,
            'customer' => $estimate->organization,
            'groups' => $estimate->items->groupBy(fn ($item) => $item->category ?? __('Altro')),
            'totals' => $totals,
            'years' => array_values($years),
            'advantages' => collect(preg_split('/\R/u', (string) $estimate->advantages))->map(fn ($line) => trim((string) $line, " \t-•*"))->filter()->values(),
            'vatFree' => in_array((string) $this->settings->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true),
        ]);
    }

    public function filename(Estimate $estimate): string
    {
        return "Proposta-{$estimate->number}.pdf";
    }
}
