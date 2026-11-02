<?php

namespace App\Actions\Invoices;

use App\Actions\Documents\SyncDocumentLines;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrganizationType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\VatRate;
use App\Support\CustomerSnapshot;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates draft invoices: customer and fiscal data frozen from the settings,
 * stamp duty line kept in sync, totals computed, order invoiced quantities refreshed.
 */
class SaveInvoice
{
    public function __construct(
        private Settings $settings,
        private SyncDocumentLines $syncLines,
        private InvoiceCalculator $calculator,
    ) {}

    /**
     * Fiscal defaults for a new invoice to the given customer.
     *
     * @return array{split_payment: bool, apply_withholding: bool, apply_pension: bool}
     */
    public function defaultsFor(Organization $organization): array
    {
        return [
            'split_payment' => (bool) $organization->split_payment,
            // Private individuals are not withholding agents.
            'apply_withholding' => (bool) $this->settings->get('fiscal.withholding_enabled') && $organization->type !== OrganizationType::Other,
            'apply_pension' => (bool) $this->settings->get('fiscal.pension_enabled'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Header fields plus "lines".
     */
    public function handle(Invoice $invoice, array $data): Invoice
    {
        abort_if($invoice->exists && ! $invoice->isDraft(), 409, __('Le fatture emesse non si modificano: usa una nota di credito.'));

        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        return DB::transaction(function () use ($invoice, $data, $lines): Invoice {
            $organization = Organization::withTrashed()->findOrFail($data['organization_id'] ?? $invoice->organization_id);

            $invoice->fill([...($invoice->exists ? [] : $this->defaultsFor($organization)), ...$data]);
            $invoice->type ??= InvoiceType::Invoice;
            $invoice->status = InvoiceStatus::Draft;
            $invoice->series = $invoice->type->series();
            $invoice->customer = CustomerSnapshot::from($organization);

            $term = $invoice->payment_term_id ? PaymentTerm::find($invoice->payment_term_id) : null;
            $invoice->payment_terms = $term?->name;
            $invoice->payment_method = $term?->method;

            $this->freezeFiscalData($invoice);
            $invoice->save();

            if ($lines !== null) {
                $this->syncLines->handle($invoice->lines(), array_values(array_filter(
                    $lines,
                    fn (array $line) => ($line['system'] ?? null) !== InvoiceLine::SYSTEM_STAMP_DUTY,
                )), ['order_line_id', 'system', 'deducts_invoice_id']);
            }

            $this->syncStampDuty($invoice);
            $this->refreshTotals($invoice);

            if ($invoice->order_id) {
                Order::find($invoice->order_id)?->refreshInvoicedQuantities();
            }

            return $invoice;
        });
    }

    public function refreshTotals(Invoice $invoice): void
    {
        $totals = $this->calculator->calculate($invoice, $invoice->lines()->get());

        $invoice->forceFill([
            'subtotal' => $totals['subtotal'],
            'pension_amount' => $totals['pension_amount'],
            'vat_total' => $totals['vat_total'],
            'total' => $totals['total'],
            'withholding_amount' => $totals['withholding_amount'],
            'amount_due' => $totals['amount_due'],
        ])->save();
    }

    /**
     * Regime, withholding and pension fund parameters as configured now.
     */
    private function freezeFiscalData(Invoice $invoice): void
    {
        $pensionRate = $this->settings->get('fiscal.pension_vat_rate_id') ? VatRate::find($this->settings->get('fiscal.pension_vat_rate_id')) : null;
        $pensionRate ??= VatRate::default();

        $invoice->forceFill([
            'regime' => $this->settings->get('fiscal.regime'),
            'withholding_type' => $this->settings->get('fiscal.withholding_type'),
            'withholding_rate' => $invoice->apply_withholding ? $this->settings->get('fiscal.withholding_rate') : 0,
            'withholding_reason' => $this->settings->get('fiscal.withholding_reason'),
            'pension_type' => $this->settings->get('fiscal.pension_type'),
            'pension_rate' => $invoice->apply_pension ? $this->settings->get('fiscal.pension_rate') : 0,
            'pension_vat_rate' => $pensionRate?->rate ?? 0,
            'pension_vat_nature' => $pensionRate?->nature,
            'pension_withholding' => (bool) $this->settings->get('fiscal.pension_withholding'),
        ]);
    }

    /**
     * Adds or removes the "Rimborso imposta di bollo" line and records the stamp duty.
     */
    private function syncStampDuty(Invoice $invoice): void
    {
        $invoice->lines()->where('system', InvoiceLine::SYSTEM_STAMP_DUTY)->delete();

        $due = (bool) $this->settings->get('fiscal.stamp_duty_enabled')
            && $this->calculator->stampDutyDue($this->calculator->exemptAmount($invoice->lines()->get(), $invoice));

        $invoice->forceFill(['stamp_duty_amount' => $due ? FiscalCodes::STAMP_DUTY_AMOUNT : 0])->save();

        if (! $due || ! $this->settings->get('fiscal.stamp_duty_charge_customer')) {
            return;
        }

        $rate = VatRate::query()->where('nature', 'N1')->orderByDesc('is_active')->first()
            ?? VatRate::create(['name' => 'Escluso art. 15', 'rate' => 0, 'nature' => 'N1', 'legal_reference' => 'Escluso art. 15 DPR 633/72', 'is_active' => true, 'sort' => 50]);

        $invoice->lines()->create([
            'position' => (int) $invoice->lines()->max('position') + 1,
            'type' => 'item',
            'system' => InvoiceLine::SYSTEM_STAMP_DUTY,
            'description' => __('Rimborso imposta di bollo'),
            'quantity' => 1,
            'unit_price' => FiscalCodes::STAMP_DUTY_AMOUNT,
            'discount_percent' => 0,
            'vat_rate_id' => $rate->id,
            'vat_rate' => 0,
            'vat_nature' => 'N1',
            'total' => FiscalCodes::STAMP_DUTY_AMOUNT,
        ]);
    }
}
