<?php

namespace App\Actions\Estimates;

use App\Actions\Documents\SyncDocumentLines;
use App\Actions\Invoices\RegimeVatRate;
use App\Actions\Invoices\SaveInvoice;
use App\Enums\BillingPeriod;
use App\Enums\InvoiceType;
use App\Enums\QuoteLineType;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\VatRate;
use App\Support\DocumentNumber;
use App\Support\Estimates\EstimateCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accepted estimate → monthly service contract of the customer for the term (its fees are invoiced
 * with «Genera fatture da servizi»), plus a draft invoice of the upfront payment when there is one.
 */
class AcceptEstimate
{
    public function __construct(
        private EstimateCalculator $calculator,
        private SyncDocumentLines $syncLines,
        private RegimeVatRate $vatRates,
        private SaveInvoice $saveInvoice,
    ) {}

    /**
     * @return array{subscription: Subscription, invoice: Invoice|null}
     */
    public function handle(Estimate $estimate, int $userId): array
    {
        abort_if($estimate->isAccepted(), 409, __('La proposta è già accettata.'));
        abort_if($estimate->organization_id === null, 409, __('Scegli il cliente prima di accettare la proposta.'));

        $estimate->loadMissing('items');
        $totals = $this->calculator->summarize($estimate);
        abort_if($totals['monthly'] <= 0, 409, __('Il canone mensile deve essere maggiore di zero.'));

        $customer = Organization::query()->withTrashed()->findOrFail($estimate->organization_id);
        $start = $estimate->starts_on ? CarbonImmutable::parse($estimate->starts_on->toDateString()) : CarbonImmutable::parse(now(config('app.display_timezone'))->toDateString())->addMonthNoOverflow()->startOfMonth();
        $vatRateId = $this->vatRates->for(VatRate::default()?->id);

        return DB::transaction(function () use ($estimate, $totals, $customer, $start, $vatRateId, $userId): array {
            $subscription = new Subscription([
                'organization_id' => $customer->id,
                'title' => $estimate->title,
                'billing_period' => BillingPeriod::Monthly,
                'billing_mode' => Subscription::MODE_ADVANCE,
                'starts_on' => $start->toDateString(),
                'ends_on' => $start->addMonthsNoOverflow($totals['months'])->subDay()->toDateString(),
                'payment_term_id' => $customer->payment_term_id,
                'owner_id' => $userId,
                'is_active' => true,
                'notes' => __('Dalla proposta :number (:months mesi).', ['number' => $estimate->number, 'months' => $totals['months']]),
            ]);
            $subscription->forceFill(DocumentNumber::next(Subscription::class, 'CS', (int) now()->year))->save();

            $this->syncLines->handle($subscription->lines(), [[
                'type' => QuoteLineType::Item,
                'description' => __('Canone :title', ['title' => $estimate->title]),
                'unit' => 'mese',
                'quantity' => 1,
                'unit_price' => $totals['monthly'],
                'discount_percent' => 0,
                'vat_rate_id' => $vatRateId,
            ]]);

            $invoice = $totals['upfront'] > 0 ? $this->saveInvoice->handle(new Invoice, [
                'type' => InvoiceType::Invoice,
                'organization_id' => $customer->id,
                'payment_term_id' => $customer->payment_term_id,
                'title' => __('Anticipo – :title', ['title' => $estimate->title]),
                'issue_date' => today()->toDateString(),
                'owner_id' => $userId,
                'lines' => [[
                    'type' => QuoteLineType::Item,
                    'description' => __('Anticipo del contratto :title (proposta :number)', ['title' => $estimate->title, 'number' => $estimate->number]),
                    'quantity' => 1,
                    'unit_price' => $totals['upfront'],
                    'discount_percent' => 0,
                    'vat_rate_id' => $vatRateId,
                ]],
            ]) : null;

            $estimate->forceFill(['status' => Estimate::STATUS_ACCEPTED, 'subscription_id' => $subscription->id, 'starts_on' => $start->toDateString()])->save();

            return ['subscription' => $subscription, 'invoice' => $invoice];
        });
    }
}
