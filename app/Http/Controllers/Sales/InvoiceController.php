<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Invoices\BuildInvoice;
use App\Actions\Invoices\InvoiceCalculator;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\SaveInvoice;
use App\Actions\Purchases\RebillCosts;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\InvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Quote;
use App\Models\VatRate;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    use ProvidesDocumentEditorOptions;

    private const SORTABLE = ['number', 'issue_date', 'due_date', 'total'];

    public function __construct(private Settings $settings) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'type' => ['nullable', Rule::enum(InvoiceType::class)],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'organization' => ['nullable', 'integer'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $year = (int) ($filters['year'] ?? today()->year);

        $base = Invoice::query()
            ->whereYear('issue_date', $year)
            ->when($filters['organization'] ?? null, fn ($query, int $organization) => $query->where('organization_id', $organization));

        $invoices = (clone $base)
            ->with('organization')
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            // Drafts first, then by number.
            ->orderByRaw('case when status = ? then 0 else 1 end', [InvoiceStatus::Draft->value])
            ->orderBy($filters['sort_by'] ?? 'issue_date', $filters['sort_dir'] ?? 'desc')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        $issued = (clone $base)->where('status', InvoiceStatus::Issued->value);

        return Inertia::render('sales/invoices/Index', [
            'invoices' => InvoiceResource::collection($invoices),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? null,
                'type' => $filters['type'] ?? null,
                'year' => $year,
                'organization' => isset($filters['organization']) ? (int) $filters['organization'] : null,
                'sort_by' => $filters['sort_by'] ?? 'issue_date',
                'sort_dir' => $filters['sort_dir'] ?? 'desc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'summary' => [
                'drafts' => (clone $base)->where('status', InvoiceStatus::Draft->value)->count(),
                'invoiced' => round((float) (clone $issued)->where('type', '!=', InvoiceType::CreditNote->value)->sum('subtotal') - (float) (clone $issued)->where('type', InvoiceType::CreditNote->value)->sum('subtotal'), 2),
                'count' => (clone $issued)->count(),
            ],
            'years' => $this->years(),
            'types' => array_map(fn (InvoiceType $type) => ['value' => $type->value, 'title' => $type->label()], InvoiceType::cases()),
        ]);
    }

    public function create(Request $request, SaveInvoice $saveInvoice): Response
    {
        $this->authorize('create', Invoice::class);

        $organization = $request->integer('organization') ? Organization::query()->customers()->find($request->integer('organization')) : null;

        return Inertia::render('sales/invoices/Edit', [
            'invoice' => null,
            'defaults' => [
                'type' => InvoiceType::Invoice->value,
                'organization_id' => $organization?->id,
                'issue_date' => today()->toDateString(),
                'payment_term_id' => $organization?->payment_term_id ?? PaymentTerm::default()?->id,
                'notes' => $this->settings->get('fiscal.invoice_notes'),
                ...($organization ? $saveInvoice->defaultsFor($organization) : ['split_payment' => false, 'apply_withholding' => false, 'apply_pension' => (bool) $this->settings->get('fiscal.pension_enabled')]),
            ],
            ...$this->invoiceEditorOptions(),
        ]);
    }

    public function store(InvoiceRequest $request, SaveInvoice $saveInvoice): RedirectResponse
    {
        $invoice = $saveInvoice->handle(new Invoice, [...$request->validated(), 'owner_id' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza di fattura salvata. Controllala ed emettila quando è pronta.')]);

        return to_route('sales.invoices.show', $invoice);
    }

    /**
     * Invoice (or deposit) of an accepted quote, without an order.
     */
    public function storeFromQuote(Request $request, Quote $quote, BuildInvoice $build, SaveInvoice $saveInvoice): RedirectResponse
    {
        $this->authorize('view', $quote);
        $this->authorize('create', Invoice::class);
        abort_unless($quote->status === QuoteStatus::Accepted, 409, __('Solo i preventivi accettati si possono fatturare.'));

        $data = $request->validate([
            'mode' => ['required', Rule::in(['full', 'deposit'])],
            'percent' => ['exclude_unless:mode,deposit', 'nullable', 'required_without:amount', 'numeric', 'gt:0', 'max:100'],
            'amount' => ['exclude_unless:mode,deposit', 'nullable', 'numeric', 'gt:0'],
        ], [], ['percent' => 'percentuale', 'amount' => 'importo']);

        $invoice = DB::transaction(fn () => $saveInvoice->handle(new Invoice, [
            ...$build->fromQuote($quote, $data['mode'], $this->percent($data), $this->amount($data)),
            'owner_id' => $request->user()->id,
            'notes' => $this->settings->get('fiscal.invoice_notes'),
        ]));

        return $this->created($invoice);
    }

    /**
     * Invoice of an order: everything left, selected lines/quantities, or a deposit.
     */
    public function storeFromOrder(Request $request, Order $order, BuildInvoice $build, SaveInvoice $saveInvoice): RedirectResponse
    {
        $this->authorize('view', $order);
        $this->authorize('create', Invoice::class);
        abort_if($order->status === OrderStatus::Cancelled, 409, __('Un ordine annullato non si fattura.'));

        $data = $request->validate([
            'mode' => ['required', Rule::in(['full', 'selection', 'deposit'])],
            'selection' => ['exclude_unless:mode,selection', 'required', 'array'],
            'selection.*' => ['numeric', 'min:0'],
            'percent' => ['exclude_unless:mode,deposit', 'nullable', 'required_without:amount', 'numeric', 'gt:0', 'max:100'],
            'amount' => ['exclude_unless:mode,deposit', 'nullable', 'numeric', 'gt:0'],
            'deduct_deposits' => ['boolean'],
            'include_costs' => ['boolean'],
        ], [], ['percent' => 'percentuale', 'amount' => 'importo', 'selection' => 'righe da fatturare']);

        $invoice = DB::transaction(fn () => $saveInvoice->handle(new Invoice, [
            ...$build->fromOrder(
                $order,
                $data['mode'],
                array_map('floatval', $data['selection'] ?? []),
                $this->percent($data),
                $this->amount($data),
                $request->boolean('deduct_deposits', true),
                $request->boolean('include_costs') && $request->user()->can(Permission::PurchasesView->value),
            ),
            'owner_id' => $request->user()->id,
            'notes' => $this->settings->get('fiscal.invoice_notes'),
        ]));

        if ($order->status === OrderStatus::Open) {
            $order->forceFill(['status' => OrderStatus::InProgress])->save();
        }

        return $this->created($invoice);
    }

    public function show(Invoice $invoice, IssueInvoice $issueInvoice, InvoiceCalculator $calculator): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load(['lines', 'owner', 'contact', 'quote', 'order', 'relatedInvoice', 'creditNotes']);

        return Inertia::render('sales/invoices/Show', [
            'invoice' => InvoiceResource::make($invoice)->resolve(),
            'totals' => $calculator->calculate($invoice, $invoice->lines),
            'problems' => $invoice->isDraft() ? $issueInvoice->problems($invoice) : [],
            'fiscal' => $this->fiscalLabels($invoice),
        ]);
    }

    public function edit(Invoice $invoice): Response|RedirectResponse
    {
        $this->authorize('view', $invoice);

        if (! $invoice->isDraft()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('Le fatture emesse non si modificano: per correggerle emetti una nota di credito.')]);

            return to_route('sales.invoices.show', $invoice);
        }

        $this->authorize('update', $invoice);

        $invoice->load(['lines' => fn ($query) => $query->where(fn ($query) => $query->whereNull('system')->orWhere('system', '!=', 'stamp_duty'))]);

        return Inertia::render('sales/invoices/Edit', [
            'invoice' => InvoiceResource::make($invoice)->resolve(),
            'defaults' => null,
            ...$this->invoiceEditorOptions($invoice->organization_id),
        ]);
    }

    public function update(InvoiceRequest $request, Invoice $invoice, SaveInvoice $saveInvoice): RedirectResponse
    {
        $saveInvoice->handle($invoice, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza salvata.')]);

        return to_route('sales.invoices.show', $invoice);
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $order = $invoice->order;

        DB::transaction(function () use ($invoice, $order): void {
            $invoice->delete();
            $order?->refreshInvoicedQuantities();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza eliminata.')]);

        return $order ? to_route('sales.orders.show', $order) : to_route('sales.invoices.index');
    }

    public function issue(Invoice $invoice, IssueInvoice $issueInvoice): RedirectResponse
    {
        $this->authorize('issue', $invoice);

        $invoice = $issueInvoice->handle($invoice);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':type :number emessa.', ['type' => $invoice->type->label(), 'number' => $invoice->number])]);

        return to_route('sales.invoices.show', $invoice);
    }

    public function creditNote(Request $request, Invoice $invoice, BuildInvoice $build, SaveInvoice $saveInvoice): RedirectResponse
    {
        $this->authorize('creditNote', $invoice);

        $note = $saveInvoice->handle(new Invoice, [...$build->creditNote($invoice), 'owner_id' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza di nota di credito creata: modifica le righe se lo storno è parziale.')]);

        return to_route('sales.invoices.edit', $note);
    }

    /**
     * Supplier costs waiting to be charged back to a customer (invoice editor dialog).
     */
    public function costs(Request $request, RebillCosts $rebillCosts): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['integer'],
        ]);

        $exclude = $data['exclude'] ?? [];

        return response()->json([
            'data' => $rebillCosts->pending((int) $data['organization_id'])
                ->reject(fn ($line) => in_array($line->id, $exclude, true))
                ->map(fn ($line) => $rebillCosts->option($line))
                ->values(),
        ]);
    }

    private function created(Invoice $invoice): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bozza di :type pronta: controllala ed emettila.', ['type' => mb_strtolower($invoice->type->label())])]);

        return to_route('sales.invoices.show', $invoice);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function percent(array $data): ?float
    {
        return isset($data['amount']) ? null : (isset($data['percent']) ? (float) $data['percent'] : null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amount(array $data): ?float
    {
        return isset($data['amount']) ? (float) $data['amount'] : null;
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        $first = Invoice::query()->min('issue_date');
        $from = $first ? (int) substr((string) $first, 0, 4) : today()->year;

        return range(today()->year, min($from, today()->year));
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceEditorOptions(?int $organizationId = null): array
    {
        $pensionRate = $this->settings->get('fiscal.pension_vat_rate_id') ? VatRate::find($this->settings->get('fiscal.pension_vat_rate_id')) : null;
        $pensionRate ??= VatRate::default();

        return [
            ...$this->editorOptions($organizationId),
            'fiscalSettings' => [
                'regime' => $this->settings->get('fiscal.regime'),
                'regime_label' => FiscalCodes::REGIMES[$this->settings->get('fiscal.regime')] ?? null,
                'withholding_enabled' => (bool) $this->settings->get('fiscal.withholding_enabled'),
                'withholding_rate' => (float) $this->settings->get('fiscal.withholding_rate'),
                'pension_enabled' => (bool) $this->settings->get('fiscal.pension_enabled'),
                'pension_rate' => (float) $this->settings->get('fiscal.pension_rate'),
                'pension_label' => FiscalCodes::PENSION_FUNDS[$this->settings->get('fiscal.pension_type')] ?? null,
                'pension_type' => $this->settings->get('fiscal.pension_type'),
                'pension_withholding' => (bool) $this->settings->get('fiscal.pension_withholding'),
                'pension_vat_rate' => (float) ($pensionRate?->rate ?? 0),
                'pension_vat_nature' => $pensionRate?->nature,
                'stamp_duty_enabled' => (bool) $this->settings->get('fiscal.stamp_duty_enabled'),
                'stamp_duty_charge_customer' => (bool) $this->settings->get('fiscal.stamp_duty_charge_customer'),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function fiscalLabels(Invoice $invoice): array
    {
        return [
            'regime' => FiscalCodes::REGIMES[$invoice->regime] ?? $invoice->regime,
            'withholding_type' => FiscalCodes::WITHHOLDING_TYPES[$invoice->withholding_type] ?? null,
            'withholding_reason' => FiscalCodes::WITHHOLDING_REASONS[$invoice->withholding_reason] ?? null,
            'pension_type' => FiscalCodes::PENSION_FUNDS[$invoice->pension_type] ?? null,
        ];
    }
}
