<?php

namespace App\Http\Controllers\Purchases;

use App\Actions\Documents\ExpenseDraft;
use App\Actions\Expenses\SaveExpense;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseType;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sales\ProvidesDocumentEditorOptions;
use App\Http\Requests\Purchases\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\AiExtraction;
use App\Models\Expense;
use App\Models\Order;
use App\Support\Ai\DocumentReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spese: supplier invoices, receipts and credit notes, with due dates and payments.
 */
class ExpenseController extends Controller
{
    use ProvidesDocumentEditorOptions;

    private const SORTABLE = ['document_date', 'due_date', 'total'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Expense::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'unpaid', 'overdue', 'paid'])],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'supplier' => ['nullable', 'integer'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        $status = $filters['status'] ?? 'all';
        $base = Expense::query()->when($filters['supplier'] ?? null, fn ($query, int $supplier) => $query->where('supplier_id', $supplier));

        $expenses = (clone $base)
            ->search($filters['search'] ?? null)
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($status === 'unpaid', fn ($query) => $query->unpaid())
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when($status === 'paid', fn ($query) => $query->whereNotNull('paid_at'))
            ->orderBy($filters['sort_by'] ?? ($status === 'unpaid' || $status === 'overdue' ? 'due_date' : 'document_date'), $filters['sort_dir'] ?? ($status === 'unpaid' || $status === 'overdue' ? 'asc' : 'desc'))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('purchases/expenses/Index', [
            'expenses' => ExpenseResource::collection($expenses),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'category' => $filters['category'] ?? null,
                'supplier' => isset($filters['supplier']) ? (int) $filters['supplier'] : null,
                'sort_by' => $filters['sort_by'] ?? null,
                'sort_dir' => $filters['sort_dir'] ?? null,
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'summary' => [
                'unpaid' => round((float) (clone $base)->unpaid()->sum('total'), 2),
                'unpaid_count' => (clone $base)->unpaid()->count(),
                'overdue' => round((float) (clone $base)->overdue()->sum('total'), 2),
                'overdue_count' => (clone $base)->overdue()->count(),
                'due_soon' => round((float) (clone $base)->unpaid()->whereBetween('due_date', [today(), today()->addDays(30)])->sum('total'), 2),
                'month' => round((float) (clone $base)->where('type', '!=', ExpenseType::CreditNote->value)->whereBetween('document_date', [today()->startOfMonth(), today()->endOfMonth()])->sum('subtotal'), 2),
            ],
            'categories' => ExpenseCategory::options(),
        ]);
    }

    /**
     * New expense, optionally prefilled from a document read by the AI (?extraction=) or for a customer order (?order=).
     */
    public function create(Request $request, ExpenseDraft $draft): Response
    {
        $this->authorize('create', Expense::class);

        $extraction = $request->integer('extraction') ? AiExtraction::query()->where('kind', AiExtraction::KIND_EXPENSE)->find($request->integer('extraction')) : null;
        $prefill = $extraction?->succeeded() ? $draft->fromExtraction($extraction) : null;
        $order = $request->integer('order') ? Order::query()->find($request->integer('order')) : null;

        return Inertia::render('purchases/expenses/Edit', [
            'expense' => null,
            'defaults' => [
                'type' => ExpenseType::Invoice->value,
                'document_date' => today()->toDateString(),
                'order_id' => $order?->id,
                'customer_id' => $order?->organization_id,
                'lines' => [],
                ...($prefill['defaults'] ?? []),
            ],
            'review' => $prefill['review'] ?? null,
            ...$this->expenseEditorOptions(),
        ]);
    }

    public function store(ExpenseRequest $request, SaveExpense $save): RedirectResponse
    {
        $expense = $save->handle(new Expense, [...$request->validated(), 'attachment' => $request->file('attachment'), 'owner_id' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spesa registrata.')]);

        return to_route('purchases.expenses.show', $expense);
    }

    public function show(Expense $expense): Response
    {
        $this->authorize('view', $expense);

        $expense->load(['lines.rebilledBy.invoice', 'customer', 'order', 'owner', 'aiExtraction']);

        return Inertia::render('purchases/expenses/Show', [
            'expense' => ExpenseResource::make($expense)->resolve(),
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }

    public function edit(Expense $expense): Response
    {
        $this->authorize('update', $expense);

        return Inertia::render('purchases/expenses/Edit', [
            'expense' => ExpenseResource::make($expense->load(['lines', 'order']))->resolve(),
            'defaults' => null,
            'review' => null,
            ...$this->expenseEditorOptions($expense),
        ]);
    }

    public function update(ExpenseRequest $request, Expense $expense, SaveExpense $save): RedirectResponse
    {
        $save->handle($expense, [...$request->validated(), 'attachment' => $request->file('attachment')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spesa salvata.')]);

        return to_route('purchases.expenses.show', $expense);
    }

    /**
     * Records the payment (or undoes it).
     */
    public function payment(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);

        $data = $request->validate([
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ], [], ['paid_at' => 'data di pagamento', 'payment_method' => 'pagamento']);

        $expense->forceFill([
            'paid_at' => $data['paid_at'] ?? null,
            'payment_method' => $data['payment_method'] ?? $expense->payment_method,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $expense->paid_at ? __('Pagamento registrato.') : __('Spesa di nuovo da pagare.')]);

        return back();
    }

    public function attachment(Request $request, Expense $expense): StreamedResponse
    {
        $this->authorize('view', $expense);
        abort_unless($expense->attachment_path && Storage::disk(DocumentReader::DISK)->exists($expense->attachment_path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return Storage::disk(DocumentReader::DISK)->response($expense->attachment_path, $expense->attachment_name, [], $disposition);
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spesa eliminata.')]);

        return to_route('purchases.expenses.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseEditorOptions(?Expense $expense = null): array
    {
        return [
            ...$this->editorOptions(),
            'suppliers' => $this->organizationOptions(OrganizationRole::Supplier, $expense?->supplier_id),
            'customers' => $this->organizationOptions(OrganizationRole::Customer, $expense?->customer_id),
            'orders' => Order::query()
                ->whereIn('status', ['open', 'in_progress', 'completed'])
                ->when($expense?->order_id, fn ($query, int $id) => $query->orWhere('id', $id))
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
            'types' => ExpenseType::options(),
            'categories' => ExpenseCategory::options(),
            'paymentMethods' => PaymentMethod::options(),
            'canUseAi' => request()->user()->can(Permission::AiUse->value),
        ];
    }
}
