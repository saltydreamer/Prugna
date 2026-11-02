<?php

namespace App\Actions\Invoices;

use App\Actions\Documents\DocumentTotals;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\QuoteLineType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quote;
use App\Models\VatRate;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Prepares the data of a draft invoice (for SaveInvoice) from a quote, an order or another invoice:
 * - full: every line (from an order: what is still to invoice), deducting the open deposits ("saldo");
 * - selection: chosen order lines and quantities (partial invoicing);
 * - deposit: a percentage or an amount of the document, split by VAT rate ("acconto");
 * - credit note: a copy of an issued invoice.
 */
class BuildInvoice
{
    public function __construct(private Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function fromQuote(Quote $quote, string $mode, ?float $percent = null, ?float $amount = null): array
    {
        $quote->loadMissing('lines');
        $lines = $quote->lines->filter(fn ($line) => ! $line->isItem() || $line->isIncluded())->values();
        $header = $this->header($quote->organization_id, $quote->contact_id, $quote->payment_term_id, $quote->title) + ['quote_id' => $quote->id];

        if ($mode === 'deposit') {
            return [...$header, 'type' => InvoiceType::Deposit, 'lines' => $this->depositLines($lines, $percent, $amount, __('preventivo :number', ['number' => $quote->number]))];
        }

        $deposits = Invoice::query()->where('quote_id', $quote->id)->whereNull('order_id')->where('type', InvoiceType::Deposit->value)->where('status', InvoiceStatus::Issued->value)->whereDoesntHave('deductedBy')->with('lines')->get();

        return [...$header, 'type' => InvoiceType::Invoice, 'lines' => [...$this->copyLines($lines), ...$this->deductions($deposits)]];
    }

    /**
     * @param  array<int, float>  $selection  order_line_id => quantity (mode "selection")
     * @return array<string, mixed>
     */
    public function fromOrder(Order $order, string $mode, array $selection = [], ?float $percent = null, ?float $amount = null, bool $deductDeposits = true): array
    {
        $order->loadMissing('lines');
        $header = $this->header($order->organization_id, $order->contact_id, $order->payment_term_id, $order->title) + [
            'order_id' => $order->id,
            'quote_id' => $order->quote_id,
            'customer_reference' => $order->customer_reference,
            'customer_reference_date' => $order->customer_reference_date?->toDateString(),
        ];

        if ($mode === 'deposit') {
            return [...$header, 'type' => InvoiceType::Deposit, 'lines' => $this->depositLines($order->lines->values(), $percent, $amount, __('ordine :number', ['number' => $order->number]))];
        }

        $lines = [];

        foreach ($order->lines as $line) {
            if (! $line->isItem()) {
                if ($mode === 'full') {
                    $lines[] = ['type' => $line->type, 'description' => $line->description];
                }

                continue;
            }

            $quantity = $mode === 'full' ? $line->remainingQuantity() : min((float) ($selection[$line->id] ?? 0), $line->remainingQuantity());

            if ($quantity <= 0) {
                continue;
            }

            $lines[] = [
                ...$this->lineData($line),
                'quantity' => $quantity,
                'order_line_id' => $line->id,
            ];
        }

        if (! collect($lines)->contains(fn (array $line) => ($line['type'] ?? null) === QuoteLineType::Item)) {
            throw ValidationException::withMessages(['selection' => __("Non c'è nulla da fatturare: tutte le righe dell'ordine risultano già fatturate.")]);
        }

        $deductions = $deductDeposits ? $this->deductions($order->openDeposits()->with('lines')->get()) : [];

        return [...$header, 'type' => InvoiceType::Invoice, 'lines' => [...$lines, ...$deductions]];
    }

    /**
     * @return array<string, mixed>
     */
    public function creditNote(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');

        return [
            ...$this->header($invoice->organization_id, $invoice->contact_id, $invoice->payment_term_id, __('Storno fattura :number', ['number' => $invoice->number])),
            'type' => InvoiceType::CreditNote,
            'related_invoice_id' => $invoice->id,
            'order_id' => $invoice->order_id,
            'quote_id' => $invoice->quote_id,
            'split_payment' => $invoice->split_payment,
            'apply_withholding' => $invoice->apply_withholding,
            'apply_pension' => $invoice->apply_pension,
            'lines' => $invoice->lines
                ->where('system', '!=', 'stamp_duty')
                ->map(fn ($line) => $line->isItem()
                    ? [...$this->lineData($line, false), 'order_line_id' => $line->order_line_id]
                    : ['type' => $line->type, 'description' => $line->description])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function header(int $organizationId, ?int $contactId, ?int $paymentTermId, ?string $title): array
    {
        return [
            'organization_id' => $organizationId,
            'contact_id' => $contactId,
            'payment_term_id' => $paymentTermId,
            'title' => $title,
            'issue_date' => today()->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineData(object $line, bool $mapRegime = true): array
    {
        return [
            'type' => QuoteLineType::Item,
            'product_id' => $line->product_id,
            'code' => $line->code,
            'description' => $line->description,
            'unit' => $line->unit,
            'quantity' => (float) $line->quantity,
            'unit_price' => (float) $line->unit_price,
            'discount_percent' => (float) $line->discount_percent,
            'vat_rate_id' => $mapRegime ? $this->vatRateFor($line->vat_rate_id) : $line->vat_rate_id,
        ];
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function copyLines(Collection $lines): array
    {
        return $lines->map(fn ($line) => $line->isItem() ? $this->lineData($line) : ['type' => $line->type, 'description' => $line->description])->values()->all();
    }

    /**
     * One deposit line per VAT rate of the source, proportional to its taxable amount.
     *
     * @param  Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function depositLines(Collection $lines, ?float $percent, ?float $amount, string $source): array
    {
        $groups = [];

        foreach ($lines->filter(fn ($line) => $line->isItem() && $line->isIncluded()) as $line) {
            $rateId = $this->vatRateFor($line->vat_rate_id);
            $groups[$rateId] = ($groups[$rateId] ?? 0) + DocumentTotals::lineTotal((float) $line->quantity, (float) $line->unit_price, (float) $line->discount_percent);
        }

        $taxable = array_sum($groups);

        if ($taxable <= 0) {
            throw ValidationException::withMessages(['percent' => __('Il documento non ha importi da cui calcolare un acconto.')]);
        }

        $target = $amount !== null ? round($amount, 2) : round($taxable * (float) $percent / 100, 2);

        if ($target <= 0 || $target > $taxable) {
            throw ValidationException::withMessages([$amount !== null ? 'amount' : 'percent' => __("L'acconto deve essere maggiore di zero e non superare l'imponibile (:max).", ['max' => number_format($taxable, 2, ',', '.')])]);
        }

        $description = $amount !== null
            ? __('Acconto su :source', ['source' => $source])
            : __('Acconto del :percent% su :source', ['percent' => rtrim(rtrim(number_format((float) $percent, 2, ',', ''), '0'), ','), 'source' => $source]);

        $result = [];
        $allocated = 0.0;
        $rateIds = array_keys($groups);

        foreach ($rateIds as $index => $rateId) {
            $share = $index === array_key_last($rateIds)
                ? round($target - $allocated, 2)
                : round($target * $groups[$rateId] / $taxable, 2);
            $allocated += $share;

            $result[] = [
                'type' => QuoteLineType::Item,
                'description' => count($groups) > 1 ? $description.' – '.VatRate::find($rateId)?->name : $description,
                'quantity' => 1,
                'unit_price' => $share,
                'discount_percent' => 0,
                'vat_rate_id' => $rateId,
            ];
        }

        return $result;
    }

    /**
     * Negative lines deducting each open deposit invoice, per VAT rate.
     *
     * @param  Collection<int, Invoice>  $deposits
     * @return list<array<string, mixed>>
     */
    private function deductions(Collection $deposits): array
    {
        $lines = [];

        foreach ($deposits as $deposit) {
            foreach ($deposit->lines->filter(fn ($line) => $line->isItem()) as $line) {
                $lines[] = [
                    'type' => QuoteLineType::Item,
                    'system' => 'deposit_deduction',
                    'deducts_invoice_id' => $deposit->id,
                    'description' => __('Detrazione acconto :number del :date', [
                        'number' => $deposit->displayNumber(),
                        'date' => $deposit->issue_date->format('d/m/Y'),
                    ]),
                    'quantity' => 1,
                    'unit_price' => -1 * (float) $line->total,
                    'discount_percent' => 0,
                    'vat_rate_id' => $line->vat_rate_id,
                ];
            }
        }

        return $lines;
    }

    /**
     * In the flat-rate regime every line is "non soggetta" (N2.2).
     */
    private function vatRateFor(?int $vatRateId): ?int
    {
        if (! in_array($this->settings->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true)) {
            return $vatRateId;
        }

        return VatRate::query()->where('nature', 'N2.2')->value('id') ?? $vatRateId;
    }
}
