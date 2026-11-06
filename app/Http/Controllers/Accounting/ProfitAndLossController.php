<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Support\Accounting\FlatRateTaxEstimate;
use App\Support\Accounting\ProfitAndLoss;
use App\Support\Accounting\Receivables;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contabilità → Conto economico: revenue and costs on a cash basis, by month, category and
 * line of business, with the flat-rate tax estimate.
 */
class ProfitAndLossController extends Controller
{
    public function __invoke(Request $request, ProfitAndLoss $profitAndLoss, FlatRateTaxEstimate $estimate, Receivables $receivables): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $years = $profitAndLoss->years();
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? $years[0]);
        $current = $profitAndLoss->forYear($year);
        $previous = $profitAndLoss->forYear($year - 1);

        return Inertia::render('accounting/Index', [
            'year' => $year,
            'years' => array_values(array_unique([...$years, $year])),
            'report' => $current,
            'previous' => ['revenue' => $previous['revenue'], 'costs' => $previous['costs'], 'net' => $previous['net']],
            'estimate' => $estimate->applies() ? $estimate->estimate($current) : null,
            'parameters' => $estimate->applies() ? $estimate->parameters() : null,
            'open' => $receivables->summary(),
        ]);
    }
}
