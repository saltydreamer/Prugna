<?php

namespace App\Actions\Invoices;

use App\Actions\Documents\DocumentTotals;
use App\Actions\Interventions\BillInterventions;
use App\Actions\Purchases\RebillCosts;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\QuoteLineType;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\VatRate;
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
    public function __construct(private Settings $settings, private RebillCosts $rebillCosts, private RegimeVatRate $vatRates, private BillInterventions $billInterventions) {}

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
     * Invoice charging back chosen costs (supplier order lines, expenses) of a customer, optionally for an order.
     *
     * @param  list<string>  $keys  Cost keys ("po-12", "ex-7").
     * @param  array<string, mixed>  $markups  key => markup %
     * @return array<string, mixed>
     */
    public function fromCosts(int $customerId, ?Order $order, array $keys, array $markups = [], bool $grouped = false, ?string $description = null, bool $listDetails = true): array
    {
        $costs = $this->rebillCosts->resolve($keys);

        if ($costs->isEmpty()) {
            throw ValidationException::withMessages(['costs' => __('Scegli almeno un costo da fatturare.')]);
        }

        $customer = Organization::query()->findOrFail($customerId);
        $header = $this->header($customer->id, null, $order?->payment_term_id ?? $customer->payment_term_id, $order?->title ?? __('Rimborso spese'));

        return [
            ...$header,
            'type' => InvoiceType::Invoice,
            'order_id' => $order?->id,
            'quote_id' => $order?->quote_id,
            'customer_reference' => $order?->customer_reference,
            'customer_reference_date' => $order?->customer_reference_date?->toDateString(),
            'lines' => $this->rebillCosts->lines($costs, $markups, $grouped, $description, $listDetails),
        ];
    }

    /**
     * Invoice for interventions of a customer, optionally for an order.
     *
     * @param  Collection<int, Intervention>  $interventions
     * @return array<string, mixed>
     */
    public function fromInterventions(int $customerId, ?Order $order, Collection $interventions, bool $grouped = false, bool $listDetails = true): array
    {
        $lines = $this->billInterventions->lines($interventions, $grouped, $listDetails);

        if ($lines === []) {
            throw ValidationException::withMessages(['interventions' => __('Gli interventi scelti non hanno nulla da fatturare.')]);
        }

        $customer = Organization::query()->findOrFail($customerId);
        $header = $this->header($customer->id, null, $order?->payment_term_id ?? $customer->payment_term_id, $order?->title ?? __('Interventi di assistenza :period', ['period' => BillInterventions::period($interventions)]));

        return [
            ...$header,
            'type' => InvoiceType::Invoice,
            'order_id' => $order?->id,
            'quote_id' => $order?->quote_id,
            'customer_reference' => $order?->customer_reference,
            'customer_reference_date' => $order?->customer_reference_date?->toDateString(),
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<int, float>  $selection  order_line_id => quantity (mode "selection")
     * @return array<string, mixed>
     */
    public function fromOrder(Order $order, string $mode, array $selection = [], ?float $percent = null, ?float $amount = null, bool $deductDeposits = true, bool $includeCosts = false): array
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

        // Headings without anything left to invoice under them are dropped.
        if (! collect($lines)->contains(fn (array $line) => ($line['type'] ?? null) === QuoteLineType::Item)) {
            $lines = [];
        }

        if ($includeCosts) {
            $lines = [...$lines, ...$this->costLines($order)];
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
            // Same references as the invoice: public bodies check the CIG/CUP on credit notes too.
            'customer_reference' => $invoice->customer_reference,
            'customer_reference_date' => $invoice->customer_reference_date?->toDateString(),
            'cig' => $invoice->cig,
            'cup' => $invoice->cup,
            'split_payment' => $invoice->split_payment,
            'apply_withholding' => $invoice->apply_withholding,
            'apply_pension' => $invoice->apply_pension,
            'charge_stamp_duty' => $invoice->charge_stamp_duty,
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
     * Supplier costs of the order to charge back, under their own heading.
     *
     * @return list<array<string, mixed>>
     */
    private function costLines(Order $order): array
    {
        $costs = $this->rebillCosts->pending($order->organization_id, $order);

        if ($costs->isEmpty()) {
            return [];
        }

        return [
            ['type' => QuoteLineType::Section, 'description' => __('Costi sostenuti per vostro conto')],
            ...$this->rebillCosts->lines($costs),
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

    private function vatRateFor(?int $vatRateId): ?int
    {
        return $this->vatRates->for($vatRateId);
    }
}
