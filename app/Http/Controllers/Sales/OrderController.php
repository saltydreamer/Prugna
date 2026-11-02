<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Orders\SaveOrder;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use ProvidesDocumentEditorOptions;

    private const SORTABLE = ['number', 'order_date', 'delivery_date', 'total'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Order::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_column(OrderStatus::cases(), 'value'), 'active', 'to_invoice'])],
            'organization' => ['nullable', 'integer'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $status = $filters['status'] ?? 'active';

        $base = Order::query()
            ->when($filters['organization'] ?? null, fn ($query, int $organization) => $query->where('organization_id', $organization));

        $orders = (clone $base)
            ->with(['organization', 'owner'])
            ->withInvoicedAmount()
            ->search($filters['search'] ?? null)
            ->when($status === 'active', fn ($query) => $query->whereIn('status', [OrderStatus::Open->value, OrderStatus::InProgress->value]))
            ->when($status === 'to_invoice', fn ($query) => $query->toInvoice())
            ->when(OrderStatus::tryFrom($status), fn ($query, OrderStatus $status) => $query->where('status', $status->value))
            ->orderBy($filters['sort_by'] ?? 'number', $filters['sort_dir'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('sales/orders/Index', [
            'orders' => OrderResource::collection($orders),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'organization' => isset($filters['organization']) ? (int) $filters['organization'] : null,
                'sort_by' => $filters['sort_by'] ?? 'number',
                'sort_dir' => $filters['sort_dir'] ?? 'desc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'statuses' => OrderStatus::options(),
            'counts' => [
                'active' => (clone $base)->whereIn('status', [OrderStatus::Open->value, OrderStatus::InProgress->value])->count(),
                'to_invoice' => (clone $base)->toInvoice()->count(),
                ...(clone $base)->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count) => (int) $count)->all(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Order::class);

        $organization = $request->integer('organization') ? Organization::query()->find($request->integer('organization')) : null;

        return Inertia::render('sales/orders/Edit', [
            'order' => null,
            'defaults' => [
                'organization_id' => $organization?->id,
                'owner_id' => $request->user()->id,
                'order_date' => today()->toDateString(),
                'payment_term_id' => $organization?->payment_term_id ?? PaymentTerm::default()?->id,
            ],
            ...$this->editorOptions(),
        ]);
    }

    public function store(OrderRequest $request, SaveOrder $saveOrder): RedirectResponse
    {
        $order = $saveOrder->handle(new Order, [...$request->validated(), 'owner_id' => $request->validated('owner_id') ?? $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine :number creato.', ['number' => $order->number])]);

        return to_route('sales.orders.show', $order);
    }

    /**
     * The order of an accepted quote (opened if it already exists).
     */
    public function storeFromQuote(Request $request, Quote $quote, SaveOrder $saveOrder): RedirectResponse
    {
        $this->authorize('view', $quote);
        $this->authorize('create', Order::class);
        abort_unless($quote->status === QuoteStatus::Accepted, 409, __('Solo i preventivi accettati diventano ordini.'));

        $existing = Order::query()->where('quote_id', $quote->id)->exists();
        $order = $saveOrder->fromQuote($quote, $request->user()->id);

        if (! $existing) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine :number creato dal preventivo :quote.', ['number' => $order->number, 'quote' => $quote->number])]);
        }

        return to_route('sales.orders.show', $order);
    }

    public function show(Order $order): Response
    {
        $this->authorize('view', $order);

        $order->load(['lines', 'owner', 'contact', 'quote', 'invoices']);

        return Inertia::render('sales/orders/Show', [
            'order' => OrderResource::make($order)->resolve(),
            'billing' => $order->billingSummary(),
            'openDeposits' => $order->openDeposits()->count(),
            'statuses' => OrderStatus::options(),
            'organizationArchived' => $order->organization?->trashed() ?? false,
        ]);
    }

    public function edit(Order $order): Response
    {
        $this->authorize('update', $order);

        return Inertia::render('sales/orders/Edit', [
            'order' => OrderResource::make($order->load('lines'))->resolve(),
            'defaults' => null,
            ...$this->editorOptions($order->organization_id),
        ]);
    }

    public function update(OrderRequest $request, Order $order, SaveOrder $saveOrder): RedirectResponse
    {
        $saveOrder->handle($order, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine salvato.')]);

        return to_route('sales.orders.show', $order);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        $data = $request->validate(['status' => ['required', Rule::enum(OrderStatus::class)]]);

        $order->forceFill(['status' => $data['status']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine :number: :status.', ['number' => $order->number, 'status' => mb_strtolower($order->status->label())])]);

        return back();
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->authorize('delete', $order);

        $order->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine :number eliminato.', ['number' => $order->number])]);

        return to_route('sales.orders.index');
    }
}
