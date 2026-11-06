<?php

namespace App\Actions\Subscriptions;

use App\Actions\Invoices\RegimeVatRate;
use App\Actions\Invoices\SaveInvoice;
use App\Enums\InvoiceType;
use App\Enums\QuoteLineType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionLine;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Genera fatture da servizi»: the fees of the service contracts due by a date become draft
 * invoices, one per customer and payment term, with one line per service and period. The fees
 * are recorded with their invoice (SubscriptionPeriod), so they are never proposed twice.
 */
class GenerateSubscriptionInvoices
{
    public function __construct(private SaveInvoice $saveInvoice, private RegimeVatRate $vatRates, private Settings $settings) {}

    /**
     * Fees due by the date. Each one is keyed "subscription id:period start".
     *
     * @return Collection<int, array{key: string, subscription: Subscription, period: array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}}>
     */
    public function due(CarbonInterface $date, ?int $subscriptionId = null, bool $lock = false): Collection
    {
        return Subscription::query()
            ->with(['lines', 'periods', 'organization'])
            ->where('is_active', true)
            ->when($subscriptionId, fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (Subscription $subscription) => array_map(fn (array $period) => [
                'key' => $subscription->id.':'.$period['starts_on']->toDateString(),
                'subscription' => $subscription,
                'period' => $period,
            ], $subscription->duePeriods($date)))
            ->values();
    }

    /**
     * Fees due, grouped by customer, for the preview.
     *
     * @return list<array{organization: array{id: int, name: string}, total: float, fees: list<array<string, mixed>>}>
     */
    public function preview(CarbonInterface $date, ?int $subscriptionId = null): array
    {
        return $this->due($date, $subscriptionId)
            ->groupBy(fn (array $fee) => $fee['subscription']->organization_id)
            ->map(function (Collection $fees) {
                $organization = $fees->first()['subscription']->organization;

                return [
                    'organization' => ['id' => $organization->id, 'name' => $organization->name],
                    'total' => round($fees->sum(fn (array $fee) => $fee['subscription']->amount()), 2),
                    'fees' => $fees->map(fn (array $fee) => [
                        'key' => $fee['key'],
                        'subscription_id' => $fee['subscription']->id,
                        'number' => $fee['subscription']->number,
                        'title' => $fee['subscription']->title,
                        'period' => Subscription::periodLabel($fee['period']),
                        'starts_on' => $fee['period']['starts_on']->toDateString(),
                        'ends_on' => $fee['period']['ends_on']->toDateString(),
                        'due_on' => $fee['subscription']->dueDate($fee['period'])->toDateString(),
                        'amount' => $fee['subscription']->amount(),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Creates the draft invoices of the chosen fees (all the due ones when $keys is null).
     *
     * @param  list<string>|null  $keys
     * @return list<Invoice>
     */
    public function handle(CarbonInterface $date, ?array $keys, User $owner, ?int $subscriptionId = null): array
    {
        return DB::transaction(function () use ($date, $keys, $owner, $subscriptionId): array {
            $fees = $this->due($date, $subscriptionId, lock: true)
                ->when($keys !== null, fn (Collection $fees) => $fees->whereIn('key', $keys))
                ->filter(fn (array $fee) => $fee['subscription']->lines->contains(fn (SubscriptionLine $line) => $line->isItem()));

            $groups = $fees->groupBy(fn (array $fee) => $fee['subscription']->organization_id.'-'.($fee['subscription']->payment_term_id ?? $fee['subscription']->organization->payment_term_id));

            return $groups->map(fn (Collection $fees) => $this->invoice($fees, $owner))->values()->all();
        });
    }

    /**
     * @param  Collection<int, array{key: string, subscription: Subscription, period: array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}}>  $fees
     */
    private function invoice(Collection $fees, User $owner): Invoice
    {
        $first = $fees->first()['subscription'];
        /** @var Organization $customer */
        $customer = $first->organization;
        $bySubscription = $fees->groupBy(fn (array $fee) => $fee['subscription']->id);
        $lines = [];

        foreach ($bySubscription as $subscriptionFees) {
            $subscription = $subscriptionFees->first()['subscription'];

            // Several contracts in the same invoice: a heading for each one.
            if ($bySubscription->count() > 1) {
                $lines[] = ['type' => QuoteLineType::Section, 'description' => $subscription->title];
            }

            foreach ($subscriptionFees as $fee) {
                $label = Subscription::periodLabel($fee['period']);

                foreach ($subscription->lines->filter(fn (SubscriptionLine $line) => $line->isItem()) as $line) {
                    $lines[] = [
                        'type' => QuoteLineType::Item,
                        'product_id' => $line->product_id,
                        'code' => $line->code,
                        'description' => __(':description – canone :period', ['description' => $line->description, 'period' => $label]),
                        'unit' => $line->unit,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'discount_percent' => (float) $line->discount_percent,
                        'vat_rate_id' => $this->vatRates->for($line->vat_rate_id),
                    ];
                }
            }
        }

        $invoice = $this->saveInvoice->handle(new Invoice, [
            'type' => InvoiceType::Invoice,
            'organization_id' => $customer->id,
            'payment_term_id' => $first->payment_term_id ?? $customer->payment_term_id,
            'title' => $bySubscription->count() === 1 ? $first->title : __('Canoni dei servizi'),
            'issue_date' => today()->toDateString(),
            'owner_id' => $owner->id,
            'notes' => $this->settings->get('fiscal.invoice_notes'),
            'lines' => $lines,
        ]);

        foreach ($fees as $fee) {
            $fee['subscription']->periods()->create([
                'starts_on' => $fee['period']['starts_on']->toDateString(),
                'ends_on' => $fee['period']['ends_on']->toDateString(),
                'invoice_id' => $invoice->id,
            ]);
        }

        return $invoice;
    }
}
