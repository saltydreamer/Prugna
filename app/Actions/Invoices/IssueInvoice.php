<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Support\CustomerSnapshot;
use App\Support\DocumentNumber;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a draft: checks the data required by law and by the SDI, assigns the progressive
 * number of its series (FT / NC) for the year and computes the due date.
 */
class IssueInvoice
{
    public function __construct(private Settings $settings, private SaveInvoice $saveInvoice) {}

    public function handle(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            // Latest customer data, then checks.
            $invoice->customer = CustomerSnapshot::from(Organization::withTrashed()->findOrFail($invoice->organization_id));
            $this->validate($invoice);

            $invoice->forceFill([
                ...DocumentNumber::next(Invoice::class, $invoice->series, $invoice->issue_date->year, ['series' => $invoice->series]),
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'due_date' => $invoice->paymentTerm?->dueDate($invoice->issue_date) ?? $invoice->issue_date,
            ])->save();

            $this->saveInvoice->refreshTotals($invoice);

            if ($invoice->order_id) {
                Order::find($invoice->order_id)?->refreshInvoicedQuantities();
            }

            return $invoice;
        });
    }

    /**
     * Problems that prevent issuing, as field => message (empty when the invoice can be issued).
     *
     * @return array<string, string>
     */
    public function problems(Invoice $invoice): array
    {
        $problems = [];
        $company = $this->settings->group('company');
        $customer = $invoice->customer ?? [];

        if (! $invoice->isDraft()) {
            $problems['status'] = __('La fattura è già stata emessa.');
        }

        foreach (['name' => 'la ragione sociale', 'vat_number' => 'la partita IVA', 'address' => "l'indirizzo", 'postal_code' => 'il CAP', 'city' => 'la città'] as $field => $label) {
            if (blank($company[$field] ?? null)) {
                $problems['company'] = __('Completa i dati della tua azienda in Impostazioni → Azienda: manca :field.', ['field' => $label]);
                break;
            }
        }

        if (blank($customer['vat_number'] ?? null) && blank($customer['tax_code'] ?? null)) {
            $problems['customer'] = __('Il cliente deve avere partita IVA o codice fiscale.');
        } elseif (blank($customer['address'] ?? null) || blank($customer['city'] ?? null) || blank($customer['postal_code'] ?? null)) {
            $problems['customer'] = __('Il cliente deve avere una sede principale con indirizzo, CAP e città.');
        } elseif (($customer['country'] ?? 'IT') === 'IT' && blank($customer['province'] ?? null)) {
            $problems['customer'] = __('Indica la provincia della sede principale del cliente.');
        } elseif (($customer['is_public_administration'] ?? false) && strlen((string) ($customer['sdi_code'] ?? '')) !== 6) {
            $problems['customer'] = __('Per la Pubblica Amministrazione serve il codice univoco ufficio (6 caratteri).');
        }

        $items = $invoice->lines()->where('type', 'item')->get();

        if ($items->isEmpty() || (float) $invoice->total <= 0) {
            $problems['lines'] = __("L'importo della fattura deve essere maggiore di zero.");
        }

        if (in_array($invoice->regime, FiscalCodes::VAT_FREE_REGIMES, true) && $items->contains(fn ($line) => (float) $line->vat_rate > 0)) {
            $problems['lines'] = __('Nel regime forfettario le righe non possono avere IVA: usa "Non soggetto (forfettario)".');
        }

        if ($items->contains(fn ($line) => (float) $line->vat_rate == 0.0 && blank($line->vat_nature))) {
            $problems['lines'] = __('Ogni riga senza IVA deve indicare la natura (N1–N7).');
        }

        if ($invoice->type === InvoiceType::CreditNote && $invoice->relatedInvoice?->isDraft() !== false) {
            $problems['related_invoice_id'] = __('La nota di credito deve riferirsi a una fattura emessa.');
        }

        if ($invoice->issue_date->isFuture()) {
            $problems['issue_date'] = __('La data della fattura non può essere futura.');
        } else {
            $lastDate = Invoice::query()
                ->where('series', $invoice->series)
                ->where('year', $invoice->issue_date->year)
                ->where('status', InvoiceStatus::Issued->value)
                ->max('issue_date');

            if ($lastDate && $invoice->issue_date->lt($lastDate)) {
                $problems['issue_date'] = __("La data non può essere precedente all'ultima fattura emessa (:date): la numerazione deve seguire l'ordine cronologico.", ['date' => Carbon::parse($lastDate)->format('d/m/Y')]);
            }
        }

        return $problems;
    }

    private function validate(Invoice $invoice): void
    {
        $problems = $this->problems($invoice);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }
}
