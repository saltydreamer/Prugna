<?php

namespace App\Support\Accounting;

use App\Enums\AccountingCategoryKind;
use App\Enums\ExpenseType;
use App\Models\AccountingCategory;
use App\Models\AccountMovement;
use App\Models\BusinessLine;
use App\Models\Expense;
use App\Models\InvoiceLine;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Profit and loss on a cash basis (as in the flat-rate regime):
 * - revenue: collections of invoices (refunds of credit notes subtracted) when they reach an
 *   account, without VAT and with the withholding tax added back; other revenue from movements;
 * - costs: expenses when paid (VAT included in the VAT-free regimes, where it is not deductible),
 *   only for their business share; movements without a document by their category;
 * - taxes and social security contributions apart, below the operating result.
 * Movements without a category (e.g. personal withdrawals) and transfers stay out.
 */
class ProfitAndLoss
{
    public const UNASSIGNED = 0;

    public function __construct(private Settings $settings) {}

    /**
     * Years with something to show, newest first (the current one always).
     *
     * @return list<int>
     */
    public function years(): array
    {
        $years = collect([(int) now()->year])
            ->merge(AccountMovement::query()->pluck('date')->map(fn ($date) => (int) $date->year))
            ->merge(Expense::query()->whereNotNull('paid_at')->pluck('paid_at')->map(fn ($date) => (int) $date->year));

        return $years->unique()->sortDesc()->values()->all();
    }

    /**
     * @return array{
     *     year: int,
     *     collected: float, other_revenue: float, revenue: float, costs: float, operating: float,
     *     taxes: float, contributions: float, net: float,
     *     months: list<array{month: int, revenue: float, costs: float, taxes: float, net: float}>,
     *     categories: list<array{id: int|null, name: string, kind: string, amount: float}>,
     *     lines: list<array{id: int, name: string, revenue: float, costs: float, margin: float}>,
     *     unclassified: array{count: int, in: float, out: float}
     * }
     */
    public function forYear(int $year): array
    {
        $months = array_fill(1, 12, ['revenue' => 0.0, 'costs' => 0.0, 'taxes' => 0.0]);
        $categories = [];
        $lines = [];
        $totals = ['collected' => 0.0, 'other_revenue' => 0.0, 'costs' => 0.0, 'taxes' => 0.0, 'contributions' => 0.0];
        $unclassified = ['count' => 0, 'in' => 0.0, 'out' => 0.0];

        $addLine = function (int $lineId, string $field, float $amount) use (&$lines): void {
            $lines[$lineId] ??= ['revenue' => 0.0, 'costs' => 0.0];
            $lines[$lineId][$field] += $amount;
        };

        $addOutflow = function (?AccountingCategory $category, int $month, float $amount, ?int $lineId) use (&$months, &$categories, &$totals, $addLine): void {
            $kind = $category?->kind ?? AccountingCategoryKind::Cost;
            $key = $category?->id ?? 0;
            $categories[$key] ??= ['id' => $category?->id, 'name' => $category?->name ?? __('Senza categoria'), 'kind' => $kind->value, 'amount' => 0.0];
            $categories[$key]['amount'] += $amount;

            match ($kind) {
                AccountingCategoryKind::Tax => $totals['taxes'] += $amount,
                AccountingCategoryKind::Contribution => $totals['contributions'] += $amount,
                default => $totals['costs'] += $amount,
            };

            if ($kind === AccountingCategoryKind::Cost) {
                $months[$month]['costs'] += $amount;
                $addLine($lineId ?? self::UNASSIGNED, 'costs', $amount);
            } else {
                $months[$month]['taxes'] += $amount;
            }
        };

        // Collections of invoices and refunds of credit notes.
        $collections = AccountMovement::query()
            ->whereNotNull('invoice_id')
            ->whereYear('date', $year)
            ->with(['invoice.lines' => fn ($query) => $query->where('type', 'item')->with('product:id,business_line_id')])
            ->get();

        foreach ($collections as $movement) {
            $invoice = $movement->invoice;
            $factor = (float) $invoice->amount_due > 0 ? (float) $invoice->subtotal / (float) $invoice->amount_due : 1.0;
            $revenue = (float) $movement->amount * $factor;

            $totals['collected'] += $revenue;
            $months[$movement->date->month]['revenue'] += $revenue;

            foreach ($this->lineShares($invoice->lines) as $lineId => $share) {
                $addLine($lineId, 'revenue', $revenue * $share);
            }
        }

        // Expenses paid in the year, for their business share.
        $vatFree = in_array((string) $this->settings->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true);
        $expenses = Expense::query()->whereNotNull('paid_at')->whereYear('paid_at', $year)->with('category')->get();

        foreach ($expenses as $expense) {
            $amount = (float) ($vatFree ? $expense->total : $expense->subtotal)
                * ($expense->type === ExpenseType::CreditNote ? -1 : 1)
                * (float) $expense->business_share / 100;

            $addOutflow($expense->category, $expense->paid_at->month, $amount, $expense->business_line_id);
        }

        // Entries without a document.
        $manual = AccountMovement::query()
            ->whereNull('invoice_id')->whereNull('expense_id')->whereNull('transfer_uuid')
            ->whereYear('date', $year)
            ->with('category')
            ->get();

        foreach ($manual as $movement) {
            $amount = (float) $movement->amount;

            if ($movement->category === null) {
                $unclassified['count']++;
                $unclassified[$amount >= 0 ? 'in' : 'out'] += abs($amount);

                continue;
            }

            if ($movement->category->kind === AccountingCategoryKind::Revenue) {
                $totals['other_revenue'] += $amount;
                $months[$movement->date->month]['revenue'] += $amount;
                $addLine($movement->business_line_id ?? self::UNASSIGNED, 'revenue', $amount);

                continue;
            }

            $addOutflow($movement->category, $movement->date->month, -$amount * (float) $movement->category->business_share / 100, $movement->business_line_id);
        }

        $revenue = $totals['collected'] + $totals['other_revenue'];
        $operating = $revenue - $totals['costs'];
        $names = BusinessLine::query()->pluck('name', 'id');

        return [
            'year' => $year,
            'collected' => $this->round($totals['collected']),
            'other_revenue' => $this->round($totals['other_revenue']),
            'revenue' => $this->round($revenue),
            'costs' => $this->round($totals['costs']),
            'operating' => $this->round($operating),
            'taxes' => $this->round($totals['taxes']),
            'contributions' => $this->round($totals['contributions']),
            'net' => $this->round($operating - $totals['taxes'] - $totals['contributions']),
            'months' => array_values(array_map(fn (array $month, int $number) => [
                'month' => $number,
                'revenue' => $this->round($month['revenue']),
                'costs' => $this->round($month['costs']),
                'taxes' => $this->round($month['taxes']),
                'net' => $this->round($month['revenue'] - $month['costs'] - $month['taxes']),
            ], $months, array_keys($months))),
            'categories' => collect($categories)
                ->map(fn (array $category) => [...$category, 'amount' => $this->round($category['amount'])])
                ->filter(fn (array $category) => $category['amount'] != 0.0)
                ->sortByDesc('amount')
                ->values()
                ->all(),
            'lines' => collect($lines)
                ->map(fn (array $line, int $id) => [
                    'id' => $id,
                    'name' => $id === self::UNASSIGNED ? __('Non assegnata / spese generali') : ($names[$id] ?? '—'),
                    'revenue' => $this->round($line['revenue']),
                    'costs' => $this->round($line['costs']),
                    'margin' => $this->round($line['revenue'] - $line['costs']),
                ])
                ->sortBy(fn (array $line) => [$line['id'] === self::UNASSIGNED ? 1 : 0, -$line['revenue']])
                ->values()
                ->all(),
            'unclassified' => ['count' => $unclassified['count'], 'in' => $this->round($unclassified['in']), 'out' => $this->round($unclassified['out'])],
        ];
    }

    /**
     * How the revenue of an invoice splits among the lines of business, by its line totals
     * (lines without a product, e.g. the stamp duty charged back, are unassigned).
     *
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<int, float>
     */
    private function lineShares(Collection $lines): array
    {
        $weights = [];

        foreach ($lines as $line) {
            $lineId = $line->product?->business_line_id ?? self::UNASSIGNED;
            $weights[$lineId] = ($weights[$lineId] ?? 0) + (float) $line->total;
        }

        $sum = array_sum($weights);

        if ($sum == 0.0) {
            return [self::UNASSIGNED => 1.0];
        }

        return array_map(fn (float $weight) => $weight / $sum, $weights);
    }

    private function round(float $amount): float
    {
        return round($amount, 2) + 0.0;
    }
}
