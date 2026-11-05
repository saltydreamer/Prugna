<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Documents\SyncDocumentLines;
use App\Actions\Subscriptions\GenerateSubscriptionInvoices;
use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\VatRate;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contratti servizi: recurring services charged to customers, and «Genera fatture da servizi».
 */
class SubscriptionController extends Controller
{
    use ProvidesDocumentEditorOptions;

    public function index(Request $request, GenerateSubscriptionInvoices $generate): Response
    {
        $this->authorize('viewAny', Subscription::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'active', 'suspended'])],
        ]);

        $status = $filters['status'] ?? 'active';

        $subscriptions = Subscription::query()
            ->with(['organization', 'lines', 'periods'])
            ->search($filters['search'] ?? null)
            ->when($filters['customer'] ?? null, fn (Builder $query, int $id) => $query->where('organization_id', $id))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'suspended', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy(
                DB::table('organizations')->select('name')->whereColumn('organizations.id', 'subscriptions.organization_id')->limit(1)
            )
            ->orderBy('title')
            ->paginate(100)
            ->withQueryString();

        return Inertia::render('sales/subscriptions/Index', [
            'subscriptions' => SubscriptionResource::collection($subscriptions),
            'filters' => ['search' => $filters['search'] ?? '', 'customer' => isset($filters['customer']) ? (int) $filters['customer'] : null, 'status' => $status],
            'customers' => $this->organizationOptions(OrganizationRole::Customer),
            'dueCount' => $generate->due(Subscription::today())->count(),
            'canInvoice' => $request->user()->can('invoice', Subscription::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Subscription::class);

        return Inertia::render('sales/subscriptions/Edit', [
            'subscription' => null,
            'defaults' => [
                'organization_id' => $request->integer('organization') ?: null,
                'title' => '',
                'billing_period' => BillingPeriod::Monthly->value,
                'billing_mode' => Subscription::MODE_ADVANCE,
                'starts_on' => Subscription::today()->startOfMonth()->toDateString(),
                'ends_on' => null,
                'billed_until' => null,
                'payment_term_id' => null,
                'owner_id' => $request->user()->id,
                'is_active' => true,
                'lines' => [],
            ],
            'scheduleEditable' => true,
            ...$this->formOptions(),
        ]);
    }

    public function store(SubscriptionRequest $request, SyncDocumentLines $syncLines): RedirectResponse
    {
        $data = $request->validated();

        $subscription = DB::transaction(function () use ($data, $syncLines) {
            $subscription = new Subscription(collect($data)->except('lines')->all());
            $subscription->forceFill(DocumentNumber::next(Subscription::class, 'CS', Subscription::today()->year))->save();
            $syncLines->handle($subscription->lines(), $data['lines']);

            return $subscription;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contratto :number creato.', ['number' => $subscription->number])]);

        return to_route('sales.subscriptions.show', $subscription);
    }

    public function show(Request $request, Subscription $subscription): Response
    {
        $this->authorize('view', $subscription);

        $subscription->load(['organization', 'owner', 'paymentTerm', 'lines', 'periods.invoice']);

        return Inertia::render('sales/subscriptions/Show', [
            'subscription' => SubscriptionResource::make($subscription)->resolve(),
            'upcoming' => array_map(fn (array $period) => [
                'label' => Subscription::periodLabel($period),
                'starts_on' => $period['starts_on']->toDateString(),
                'due_on' => $subscription->dueDate($period)->toDateString(),
                'is_due' => $subscription->dueDate($period)->toDateString() <= Subscription::today()->toDateString(),
            ], $subscription->is_active ? $subscription->unbilledPeriods(null, 6) : []),
            'canInvoice' => $request->user()->can('invoice', Subscription::class),
        ]);
    }

    public function edit(Subscription $subscription): Response
    {
        $this->authorize('update', $subscription);

        $subscription->load(['lines', 'periods']);

        return Inertia::render('sales/subscriptions/Edit', [
            'subscription' => SubscriptionResource::make($subscription)->resolve(),
            'defaults' => null,
            'scheduleEditable' => ! $subscription->hasBilledPeriods(),
            ...$this->formOptions($subscription->organization_id),
        ]);
    }

    public function update(SubscriptionRequest $request, Subscription $subscription, SyncDocumentLines $syncLines): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($subscription, $data, $syncLines): void {
            $subscription->fill(collect($data)->except('lines')->all())->save();
            $syncLines->handle($subscription->lines(), $data['lines']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contratto salvato.')]);

        return to_route('sales.subscriptions.show', $subscription);
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        $this->authorize('delete', $subscription);

        $subscription->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contratto eliminato.')]);

        return to_route('sales.subscriptions.index');
    }

    /**
     * Fees due by a date, grouped by customer (preview of «Genera fatture da servizi»).
     */
    public function due(Request $request, GenerateSubscriptionInvoices $generate): JsonResponse
    {
        $this->authorize('invoice', Subscription::class);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'subscription' => ['nullable', 'integer'],
        ]);

        $date = isset($data['date']) ? CarbonImmutable::parse($data['date']) : Subscription::today();

        return response()->json(['data' => $generate->preview($date, $data['subscription'] ?? null)]);
    }

    /**
     * «Genera fatture da servizi»: draft invoices of the chosen fees, to check and issue.
     */
    public function generate(Request $request, GenerateSubscriptionInvoices $generate): RedirectResponse
    {
        $this->authorize('invoice', Subscription::class);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'fees' => ['required', 'array', 'min:1', 'max:2000'],
            'fees.*' => ['string', 'max:40'],
            'subscription' => ['nullable', 'integer'],
        ], [], ['fees' => 'canoni']);

        $invoices = $generate->handle(CarbonImmutable::parse($data['date']), $data['fees'], $request->user(), $data['subscription'] ?? null);

        if ($invoices === []) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('Nessun canone da fatturare: forse sono già in fattura.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1} Bozza di fattura pronta: controllala ed emettila.|[2,*] :count bozze di fattura pronte: controllale ed emettile.',
            count($invoices),
        )]);

        return count($invoices) === 1
            ? to_route('sales.invoices.show', $invoices[0])
            : to_route('sales.invoices.index', ['status' => InvoiceStatus::Draft->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?int $currentOrganizationId = null): array
    {
        $options = $this->editorOptions($currentOrganizationId);

        return [
            'customers' => $options['organizations'],
            'vatRates' => $options['vatRates'],
            'paymentTerms' => $options['paymentTerms'],
            'units' => $options['units'],
            'owners' => $options['owners'],
            'billingPeriods' => BillingPeriod::options(),
            'billingModes' => Subscription::modeOptions(),
            'defaultVatRateId' => VatRate::default()?->id,
        ];
    }
}
