<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Temporary, to align the data from before Prugna: expenses already paid are assigned to an order
 * already invoiced as past costs. They count in the order's costs and margin, never charged back.
 */
class OrderPastCostsController extends Controller
{
    /**
     * Paid expenses not assigned to an order (of this customer or of nobody), newest first.
     */
    public function index(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);
        $this->authorize('create', Expense::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $expenses = $this->candidates($order)
            ->with('category:id,name')
            ->search($filters['search'] ?? null)
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('document_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('document_date', '<=', $date))
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $expenses->map(fn (Expense $expense) => [
            'id' => $expense->id,
            'label' => trim($expense->type->label().' '.($expense->number ?? '')),
            'supplier' => $expense->supplier['name'] ?? null,
            'title' => $expense->title,
            'category' => $expense->category?->name,
            'document_date' => $expense->document_date->toDateString(),
            'paid_at' => $expense->paid_at?->toDateString(),
            'subtotal' => (float) $expense->subtotal,
            'total' => (float) $expense->total,
        ])]);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('view', $order);
        $this->authorize('create', Expense::class);

        $ids = $request->validate(['expenses' => ['required', 'array', 'min:1', 'max:200'], 'expenses.*' => ['integer']], [], ['expenses' => 'spese'])['expenses'];
        $expenses = $this->candidates($order)->with('lines')->whereKey($ids)->get();

        if ($expenses->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['expenses' => __('Alcune spese non sono pagate, sono già di un altro ordine o già fatturate al cliente.')]);
        }

        DB::transaction(function () use ($expenses, $order): void {
            foreach ($expenses as $expense) {
                $expense->forceFill(['order_id' => $order->id, 'customer_id' => $order->organization_id, 'past_cost' => true])->save();
                $expense->lines()->update(['rebill' => false]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            '{1} Costo pregresso imputato all\'ordine :order.|[2,*] :count costi pregressi imputati all\'ordine :order.',
            $expenses->count(),
            ['order' => $order->number, 'count' => $expenses->count()],
        )]);

        return back();
    }

    /**
     * The expense goes back to being a cost of nobody.
     */
    public function destroy(Order $order, Expense $expense): RedirectResponse
    {
        $this->authorize('view', $order);
        $this->authorize('update', $expense);
        abort_unless($expense->order_id === $order->id && $expense->past_cost, 404);

        $expense->forceFill(['order_id' => null, 'customer_id' => null, 'past_cost' => false])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Costo tolto dall\'ordine.')]);

        return back();
    }

    /**
     * Paid, not in another order, of this customer or of nobody, not charged back yet.
     *
     * @return Builder<Expense>
     */
    private function candidates(Order $order): Builder
    {
        return Expense::query()
            ->whereNotNull('paid_at')
            ->where(fn (Builder $query) => $query->whereNull('order_id')->orWhere('order_id', $order->id))
            ->where(fn (Builder $query) => $query->whereNull('customer_id')->orWhere('customer_id', $order->organization_id))
            ->where('past_cost', false)
            ->whereDoesntHave('lines.rebilledBy');
    }
}
