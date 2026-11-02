<?php

namespace App\Http\Controllers\Purchases;

use App\Actions\Purchases\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuoteLineType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\ProvidesDocumentEditorOptions;
use App\Http\Requests\Purchases\PurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\VatRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    use ProvidesDocumentEditorOptions;

    private const SORTABLE = ['number', 'order_date', 'expected_date', 'total'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...array_column(PurchaseOrderStatus::cases(), 'value'), 'open', 'all'])],
            'supplier' => ['nullable', 'integer'],
            'customer' => ['nullable', 'integer'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $status = $filters['status'] ?? 'open';
        $open = [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::Confirmed->value];

        $base = PurchaseOrder::query()
            ->when($filters['supplier'] ?? null, fn ($query, int $supplier) => $query->where('supplier_id', $supplier))
            ->when($filters['customer'] ?? null, fn ($query, int $customer) => $query->where('customer_id', $customer));

        $purchaseOrders = (clone $base)
            ->with(['customer', 'order'])
            ->search($filters['search'] ?? null)
            ->when($status === 'open', fn ($query) => $query->whereIn('status', $open))
            ->when(PurchaseOrderStatus::tryFrom($status), fn ($query, PurchaseOrderStatus $status) => $query->where('status', $status->value))
            ->orderBy($filters['sort_by'] ?? 'number', $filters['sort_dir'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('purchases/orders/Index', [
            'purchaseOrders' => PurchaseOrderResource::collection($purchaseOrders),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'supplier' => isset($filters['supplier']) ? (int) $filters['supplier'] : null,
                'customer' => isset($filters['customer']) ? (int) $filters['customer'] : null,
                'sort_by' => $filters['sort_by'] ?? 'number',
                'sort_dir' => $filters['sort_dir'] ?? 'desc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'statuses' => PurchaseOrderStatus::options(),
            'counts' => [
                'open' => (clone $base)->whereIn('status', $open)->count(),
                'all' => (clone $base)->count(),
                ...(clone $base)->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count) => (int) $count)->all(),
            ],
        ]);
    }

    /**
     * New supplier order, optionally prefilled from a customer order (?order=) or for a customer (?customer=).
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', PurchaseOrder::class);

        $order = $request->integer('order') ? Order::query()->with('lines.product')->find($request->integer('order')) : null;
        $supplier = $request->integer('supplier') ? Organization::query()->find($request->integer('supplier')) : null;

        return Inertia::render('purchases/orders/Edit', [
            'purchaseOrder' => null,
            'defaults' => [
                'supplier_id' => $supplier?->id,
                'payment_term_id' => $supplier?->payment_term_id,
                'owner_id' => $request->user()->id,
                'order_date' => today()->toDateString(),
                'order_id' => $order?->id,
                'customer_id' => $order?->organization_id ?? ($request->integer('customer') ?: null),
                'title' => $order ? __('Materiale per ordine :number', ['number' => $order->number]) : null,
                // Lines of the customer order at cost, for what has to be bought.
                'lines' => $order ? $order->lines
                    ->map(fn ($line) => $line->isItem() ? [
                        'type' => QuoteLineType::Item->value,
                        'product_id' => $line->product_id,
                        'code' => $line->code,
                        'description' => $line->product?->name ?? $line->description,
                        'unit' => $line->unit,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) ($line->product?->cost ?? 0),
                        'discount_percent' => 0,
                        'vat_rate_id' => $line->product?->vat_rate_id ?? VatRate::default()?->id,
                        'rebill' => false,
                        'markup_percent' => 0,
                    ] : ['type' => $line->type->value, 'description' => $line->description])
                    ->values()
                    ->all() : [],
            ],
            ...$this->purchaseEditorOptions(),
        ]);
    }

    public function store(PurchaseOrderRequest $request, SavePurchaseOrder $save): RedirectResponse
    {
        $purchaseOrder = $save->handle(new PurchaseOrder, [...$request->validated(), 'owner_id' => $request->validated('owner_id') ?? $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine fornitore :number creato.', ['number' => $purchaseOrder->number])]);

        return to_route('purchases.orders.show', $purchaseOrder);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load(['lines.rebilledBy.invoice', 'owner', 'contact', 'customer', 'order']);

        return Inertia::render('purchases/orders/Show', [
            'purchaseOrder' => PurchaseOrderResource::make($purchaseOrder)->resolve(),
            'statuses' => PurchaseOrderStatus::options(),
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('update', $purchaseOrder);

        return Inertia::render('purchases/orders/Edit', [
            'purchaseOrder' => PurchaseOrderResource::make($purchaseOrder->load(['lines', 'order']))->resolve(),
            'defaults' => null,
            ...$this->purchaseEditorOptions($purchaseOrder),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, SavePurchaseOrder $save): RedirectResponse
    {
        $save->handle($purchaseOrder, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine fornitore salvato.')]);

        return to_route('purchases.orders.show', $purchaseOrder);
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);

        $data = $request->validate(['status' => ['required', Rule::enum(PurchaseOrderStatus::class)]]);
        $status = PurchaseOrderStatus::from($data['status']);

        if ($status === PurchaseOrderStatus::Cancelled && $purchaseOrder->lines()->whereHas('rebilledBy')->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Alcuni costi sono già in fattura al cliente: toglili dalla fattura prima di annullare l\'ordine.')]);

            return back();
        }

        $purchaseOrder->forceFill([
            'status' => $status,
            'sent_at' => $status === PurchaseOrderStatus::Sent ? ($purchaseOrder->sent_at ?? now()) : $purchaseOrder->sent_at,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine :number: :status.', ['number' => $purchaseOrder->number, 'status' => mb_strtolower($status->label())])]);

        return back();
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('delete', $purchaseOrder);

        $purchaseOrder->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine fornitore :number eliminato.', ['number' => $purchaseOrder->number])]);

        return to_route('purchases.orders.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseEditorOptions(?PurchaseOrder $purchaseOrder = null): array
    {
        $options = $this->editorOptions($purchaseOrder?->supplier_id);
        $suppliers = Organization::query()->where('is_supplier', true)->pluck('id')->flip();

        return [
            ...$options,
            // Suppliers first, then every other organization.
            'organizations' => collect($options['organizations'])
                ->map(fn (array $option) => [...$option, 'is_supplier' => $suppliers->has($option['value'])])
                ->sortBy(fn (array $option) => ($option['is_supplier'] ? '0' : '1').mb_strtolower($option['title']))
                ->values()
                ->all(),
            'customers' => collect($options['organizations'])->values()->all(),
            'orders' => Order::query()
                ->whereIn('status', ['open', 'in_progress'])
                ->when($purchaseOrder?->order_id, fn ($query, int $id) => $query->orWhere('id', $id))
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'number', 'title', 'customer', 'organization_id'])
                ->map(fn (Order $order) => [
                    'value' => $order->id,
                    'title' => $order->number,
                    'subtitle' => trim(($order->customer['name'] ?? '').($order->title ? " · {$order->title}" : '')),
                    'organization_id' => $order->organization_id,
                ])
                ->all(),
        ];
    }
}
