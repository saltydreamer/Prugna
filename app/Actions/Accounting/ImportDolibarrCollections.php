<?php

namespace App\Actions\Accounting;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Dolibarr import kept the customer payments in the internal notes of the invoices
 * ("Incassi registrati in Dolibarr:" then "05/11/2025 · 1.234,56 € · Bonifico"): they become
 * collections on an account, so that the cash basis has the history. Invoices that already have
 * collections are skipped, so it can run again.
 */
class ImportDolibarrCollections
{
    public const MARKER = 'Incassi registrati in Dolibarr:';

    /** "05/11/2025 · 1.234,56 €" (the euro sign may follow a non-breaking space). */
    private const LINE = '/^(\d{2}\/\d{2}\/\d{4}) · (-?[\d.]+,\d{2})[\s\x{00A0}\x{202F}]*€/u';

    /**
     * Issued invoices with collections in the notes and none recorded yet.
     *
     * @return Builder<Invoice>
     */
    public function pending(): Builder
    {
        return Invoice::query()
            ->where('status', '!=', 'draft')
            ->where('internal_notes', 'like', '%'.self::MARKER.'%')
            ->whereDoesntHave('movements');
    }

    /**
     * @return array{invoices: int, collections: int, amount: float}
     */
    public function handle(int $accountId, ?int $userId = null): array
    {
        $result = ['invoices' => 0, 'collections' => 0, 'amount' => 0.0];

        DB::transaction(function () use ($accountId, $userId, &$result): void {
            foreach ($this->pending()->get() as $invoice) {
                $collections = $this->parse((string) $invoice->internal_notes);

                foreach ($collections as $collection) {
                    $invoice->movements()->create([
                        'financial_account_id' => $accountId,
                        'date' => $collection['date'],
                        'amount' => $collection['amount'],
                        'description' => __('Incasso :number – :customer (da Dolibarr)', ['number' => $invoice->displayNumber(), 'customer' => $invoice->customer['name'] ?? '']),
                        'user_id' => $userId,
                    ]);
                    $result['collections']++;
                    $result['amount'] += $collection['amount'];
                }

                if ($collections !== []) {
                    $invoice->refreshPayments();
                    $result['invoices']++;
                }
            }
        });

        $result['amount'] = round($result['amount'], 2);

        return $result;
    }

    /**
     * @return list<array{date: string, amount: float}>
     */
    public function parse(string $notes): array
    {
        $start = strpos($notes, self::MARKER);

        if ($start === false) {
            return [];
        }

        $collections = [];

        foreach (preg_split('/\R/u', ltrim(substr($notes, $start + strlen(self::MARKER)))) as $line) {
            if (! preg_match(self::LINE, trim($line), $match)) {
                if (trim($line) === '') {
                    break;
                }

                continue;
            }

            $collections[] = [
                'date' => CarbonImmutable::createFromFormat('d/m/Y', $match[1])->toDateString(),
                'amount' => round((float) str_replace(['.', ','], ['', '.'], $match[2]), 2),
            ];
        }

        return $collections;
    }
}
