<?php

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Revenue from issued invoices (imported ones included) by year, month and customer.
 *
 * Revenue is the taxable amount of the lines, VAT excluded, without the stamp duty charged
 * back to the customer; credit notes are subtracted and deposits count when issued (the
 * balance invoice deducts them). Dates are the invoice dates (accrual): in the flat-rate
 * regime the revenue threshold counts collections instead.
 */
class RevenueStatistics
{
    /** @var Collection<int, array{organization_id: int, year: int, month: int, revenue: float, counted: bool}>|null */
    private ?Collection $invoices = null;

    /**
     * Every year with invoices: revenue, invoices (credit notes excluded) and customers.
     *
     * @return list<array{year: int, revenue: float, invoices: int, customers: int}>
     */
    public function years(): array
    {
        return $this->invoices()
            ->groupBy('year')
            ->map(fn (Collection $invoices, int $year) => [
                'year' => $year,
                'revenue' => round($invoices->sum('revenue'), 2),
                'invoices' => $invoices->where('counted', true)->count(),
                'customers' => $invoices->pluck('organization_id')->unique()->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Revenue of each month of a year (January first).
     *
     * @return list<float>
     */
    public function months(int $year): array
    {
        $byMonth = $this->invoices()->where('year', $year)->groupBy('month')->map(fn (Collection $invoices) => round($invoices->sum('revenue'), 2));

        return array_map(fn (int $month) => (float) ($byMonth[$month] ?? 0), range(1, 12));
    }

    /**
     * Revenue of a year up to a day of the year (to compare a year in progress with the
     * same period of the previous one).
     */
    public function untilDayOfYear(int $year, CarbonImmutable $day): float
    {
        $limit = (int) $day->format('md');

        return round($this->invoices()->where('year', $year)->filter(fn (array $invoice) => $invoice['md'] <= $limit)->sum('revenue'), 2);
    }

    /**
     * Customers invoiced in a year, by revenue, with the previous year for comparison.
     *
     * @return list<array{id: int, name: string, revenue: float, invoices: int, previous: float, share: float}>
     */
    public function customers(int $year): array
    {
        $current = $this->invoices()->where('year', $year)->groupBy('organization_id');
        $previous = $this->invoices()->where('year', $year - 1)->groupBy('organization_id');
        $total = $this->invoices()->where('year', $year)->sum('revenue');
        $names = $this->names($current->keys());

        return $current
            ->map(fn (Collection $invoices, int $id) => [
                'id' => $id,
                'name' => $names[$id] ?? '—',
                'revenue' => round($invoices->sum('revenue'), 2),
                'invoices' => $invoices->where('counted', true)->count(),
                'previous' => round((float) ($previous->get($id)?->sum('revenue') ?? 0), 2),
                'share' => $total > 0 ? round($invoices->sum('revenue') / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Revenue of every customer in every year, by total.
     *
     * @return list<array{id: int, name: string, years: array<int, float>, total: float}>
     */
    public function customersByYear(): array
    {
        $byCustomer = $this->invoices()->groupBy('organization_id');
        $names = $this->names($byCustomer->keys());

        return $byCustomer
            ->map(fn (Collection $invoices, int $id) => [
                'id' => $id,
                'name' => $names[$id] ?? '—',
                'years' => $invoices->groupBy('year')->map(fn (Collection $year) => round($year->sum('revenue'), 2))->sortKeys()->all(),
                'total' => round($invoices->sum('revenue'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{organization_id: int, year: int, month: int, md: int, revenue: float, counted: bool}>
     */
    private function invoices(): Collection
    {
        return $this->invoices ??= Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->withSum(['lines as revenue' => fn (Builder $query) => $query
                // The stamp duty charged back to the customer is not revenue.
                ->where(fn (Builder $query) => $query->whereNull('system')->orWhere('system', '!=', 'stamp_duty'))
                ->where(fn (Builder $query) => $query->where('vat_nature', '!=', 'N1')->orWhereNull('vat_nature')->orWhere('description', 'not like', '%bollo%')),
            ], 'total')
            ->get(['id', 'type', 'organization_id', 'issue_date'])
            ->map(fn (Invoice $invoice) => [
                'organization_id' => (int) $invoice->organization_id,
                'year' => $invoice->issue_date->year,
                'month' => $invoice->issue_date->month,
                'md' => (int) $invoice->issue_date->format('md'),
                'revenue' => ($invoice->type === InvoiceType::CreditNote ? -1 : 1) * (float) $invoice->getAttribute('revenue'),
                'counted' => $invoice->type !== InvoiceType::CreditNote,
            ]);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, string>
     */
    private function names(Collection $ids): array
    {
        return Organization::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
