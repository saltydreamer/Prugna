<?php

namespace App\Actions\Accounting;

use App\Enums\InvoiceType;
use App\Models\AccountMovement;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Collection of an issued invoice on an account (or refund of a credit note: money out).
 */
class RecordInvoiceCollection
{
    public function handle(Invoice $invoice, int $accountId, string $date, float $amount, ?int $userId = null): AccountMovement
    {
        abort_if($invoice->isDraft(), 409, __('Le bozze non si incassano: emetti prima la fattura.'));

        return DB::transaction(function () use ($invoice, $accountId, $date, $amount, $userId): AccountMovement {
            $refund = $invoice->type === InvoiceType::CreditNote;

            $movement = $invoice->movements()->create([
                'financial_account_id' => $accountId,
                'date' => $date,
                'amount' => round($refund ? -abs($amount) : abs($amount), 2),
                'description' => $refund
                    ? __('Rimborso :number – :customer', ['number' => $invoice->displayNumber(), 'customer' => $invoice->customer['name'] ?? ''])
                    : __('Incasso :number – :customer', ['number' => $invoice->displayNumber(), 'customer' => $invoice->customer['name'] ?? '']),
                'user_id' => $userId,
            ]);

            $invoice->refreshPayments();

            return $movement;
        });
    }
}
