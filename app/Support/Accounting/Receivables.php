<?php

namespace App\Support\Accounting;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * What customers still have to pay and what is still to pay to suppliers.
 */
class Receivables
{
    /**
     * Issued invoices not fully collected (credit notes subtracted), oldest due first.
     *
     * @return Collection<int, Invoice>
     */
    public function invoices(): Collection
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->where('type', '!=', InvoiceType::CreditNote->value)
            ->whereColumn('paid_amount', '<', 'amount_due')
            ->with('creditNotes')
            ->orderByRaw('coalesce(due_date, issue_date)')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->outstanding() > 0)
            ->values();
    }

    /**
     * @return array{receivable: float, receivable_count: int, overdue: float, payable: float, payable_count: int, payable_overdue: float}
     */
    public function summary(): array
    {
        $invoices = $this->invoices();
        $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->isOverdue());

        return [
            'receivable' => round($invoices->sum(fn (Invoice $invoice) => $invoice->outstanding()), 2),
            'receivable_count' => $invoices->count(),
            'overdue' => round($overdue->sum(fn (Invoice $invoice) => $invoice->outstanding()), 2),
            'payable' => round((float) Expense::query()->unpaid()->sum('total'), 2),
            'payable_count' => Expense::query()->unpaid()->count(),
            'payable_overdue' => round((float) Expense::query()->overdue()->sum('total'), 2),
        ];
    }
}
