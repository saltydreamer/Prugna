<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an issued invoice (or credit note) that never reached the SDI, e.g. issued by
 * mistake: without the transmission it has no fiscal existence. Failed sends and sends to the
 * Openapi sandbox (test environment, never forwarded to the Agenzia delle Entrate) don't count.
 * Only the last one of its series and year can go, so the numbering has no gap and the next
 * invoice reuses the number; test invoices (sent only to the sandbox) are not part of the
 * fiscal numbering and can go anyway. What it billed (orders, interventions, costs,
 * contracts) goes back to be invoiced.
 */
class DeleteIssuedInvoice
{
    /**
     * Reasons why the invoice can't be deleted (empty when it can).
     *
     * @return list<string>
     */
    public function problems(Invoice $invoice): array
    {
        if ($invoice->isDraft()) {
            return [];
        }

        $problems = [];

        if ($invoice->isSentOutsidePrugna()) {
            $problems[] = __('È stata trasmessa allo SDI fuori da Prugna: per annullarla emetti una nota di credito.');
        } elseif ($invoice->hasReachedSdi()) {
            $problems[] = __('È stata inviata allo SDI: per annullarla emetti una nota di credito.');
        }

        $last = $this->laterNumber($invoice);

        if ($last !== null && ! $this->isTestInvoice($invoice)) {
            $problems[] = __('Si può eliminare solo l\'ultima emessa della serie (ora è la :number): altrimenti nella numerazione resterebbe un buco.', ['number' => $last]);
        }

        if ($invoice->creditNotes()->exists()) {
            $problems[] = __('Ha note di credito collegate: elimina prima quelle.');
        }

        if ($invoice->deductedBy()->exists()) {
            $problems[] = __('L\'acconto è già detratto in un\'altra fattura.');
        }

        return $problems;
    }

    /**
     * Whether the next invoice of the series will reuse this number once it is deleted.
     */
    public function reusesNumber(Invoice $invoice): bool
    {
        return ! $invoice->isDraft() && $this->laterNumber($invoice) === null;
    }

    /**
     * Sent only to the Openapi sandbox: a test with no fiscal value.
     */
    public function isTestInvoice(Invoice $invoice): bool
    {
        return $invoice->sdiTransmissions()->exists() && ! $invoice->sdiTransmissions()->where('environment', '!=', 'test')->exists();
    }

    /**
     * Number of the last invoice issued after this one in the same series and year.
     */
    private function laterNumber(Invoice $invoice): ?string
    {
        return Invoice::query()
            ->where('series', $invoice->series)
            ->where('year', $invoice->year)
            ->where('sequence', '>', (int) $invoice->sequence)
            ->orderByDesc('sequence')
            ->value('number');
    }

    public function handle(Invoice $invoice): void
    {
        abort_if($invoice->isDraft(), 409);
        abort_if($problems = $this->problems($invoice), 409, $problems[0] ?? '');

        DB::transaction(function () use ($invoice): void {
            // Orders billed by the invoice: its own and the ones of its lines (credit notes too).
            $orderIds = OrderLine::query()
                ->whereIn('id', $invoice->lines()->whereNotNull('order_line_id')->select('order_line_id'))
                ->pluck('order_id')
                ->push($invoice->order_id)
                ->filter()
                ->unique();

            $invoice->dolibarrLink()->delete();
            $invoice->delete();

            foreach (Order::query()->whereIn('id', $orderIds)->get() as $order) {
                $order->refreshInvoicedQuantities();
            }
        });
    }
}
