<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Support\Fiscal\FiscalCodes;
use App\Support\RevenueStatistics;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statistiche: revenue by year, month and customer from the issued invoices.
 */
class StatisticsController extends Controller
{
    public function __invoke(Request $request, RevenueStatistics $statistics, Settings $settings): Response
    {
        Gate::authorize(Permission::StatisticsView->value);

        $years = $statistics->years();
        $available = array_column($years, 'year');
        $today = now()->toImmutable();
        $year = (int) $request->integer('year');

        if (! in_array($year, $available, true)) {
            $year = in_array($today->year, $available, true) ? $today->year : (end($available) ?: null);
        }

        // A year in progress is compared with the same period of the previous one.
        $inProgress = $year === $today->year;
        $revenue = array_column($years, 'revenue', 'year');

        return Inertia::render('statistics/Index', [
            'years' => $years,
            'year' => $year,
            'months' => $year ? ['current' => $statistics->months($year), 'previous' => $statistics->months($year - 1)] : null,
            'comparison' => $year ? [
                'current' => $inProgress ? $statistics->untilDayOfYear($year, $today) : (float) ($revenue[$year] ?? 0),
                'previous' => $inProgress ? $statistics->untilDayOfYear($year - 1, $today) : (float) ($revenue[$year - 1] ?? 0),
                'until' => $inProgress ? $today->toDateString() : null,
            ] : null,
            'customers' => $year ? $statistics->customers($year) : [],
            'customersByYear' => $statistics->customersByYear(),
            'flatRate' => in_array($settings->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true),
        ]);
    }
}
