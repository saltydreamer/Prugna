<?php

namespace App\Actions\Accounting;

use App\Enums\ExpenseType;
use App\Models\Expense;

/**
 * Keeps the payment of an expense on its account in step with the expense: created when paid
 * from an account, moved with the payment day and amount, removed when the expense is unpaid.
 * Expenses paid without choosing an account count anyway in the profit and loss (by paid_at).
 */
class SyncExpensePayment
{
    public function handle(Expense $expense, ?int $accountId = null): void
    {
        $movement = $expense->movements()->first();

        if ($expense->paid_at === null) {
            $expense->movements()->delete();

            return;
        }

        $accountId ??= $movement?->financial_account_id;

        if ($accountId === null) {
            return;
        }

        // A supplier credit note is money coming back.
        $amount = $expense->type === ExpenseType::CreditNote ? (float) $expense->total : -(float) $expense->total;
        $data = [
            'financial_account_id' => $accountId,
            'date' => $expense->paid_at->toDateString(),
            'amount' => round($amount, 2),
            'description' => trim(__('Pagamento :supplier :number', [
                'supplier' => $expense->supplier['name'] ?? '',
                'number' => $expense->number ? "n. {$expense->number}" : '',
            ])),
        ];

        if ($movement) {
            $movement->update($data);
        } else {
            $expense->movements()->create([...$data, 'user_id' => auth()->id()]);
        }
    }
}
