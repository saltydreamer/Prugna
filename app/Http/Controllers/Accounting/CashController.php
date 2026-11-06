<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\ImportDolibarrCollections;
use App\Actions\Accounting\RecordInvoiceCollection;
use App\Enums\FinancialAccountType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AccountingCategory;
use App\Models\AccountMovement;
use App\Models\BusinessLine;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Support\Accounting\Receivables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contabilità → Cassa e banca: accounts with their balance and movements, transfers, collections of
 * invoices, what customers still owe and what is still to pay.
 */
class CashController extends Controller
{
    public function index(Request $request, Receivables $receivables, ImportDolibarrCollections $dolibarr): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $accounts = FinancialAccount::query()->ordered()->withCount('movements')->get();
        $selected = $accounts->firstWhere('id', $filters['account'] ?? null) ?? $accounts->firstWhere('is_active', true) ?? $accounts->first();
        $year = $filters['year'] ?? (int) now()->year;

        $movements = $selected
            ? $selected->movements()
                ->with(['category:id,name', 'businessLine:id,name', 'invoice:id,number,type,status', 'expense:id,number,supplier'])
                ->whereYear('date', $year)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
            : collect();

        return Inertia::render('accounting/Cash', [
            'accounts' => $accounts->map(fn (FinancialAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'icon' => $account->type->icon(),
                'iban' => $account->iban,
                'opening_balance' => (float) $account->opening_balance,
                'opening_date' => $account->opening_date?->toDateString(),
                'is_active' => $account->is_active,
                'balance' => $account->balance(),
                'movements_count' => $account->movements_count,
            ]),
            'selected' => $selected?->id,
            'year' => $year,
            'movements' => $movements->map(fn (AccountMovement $movement) => [
                'id' => $movement->id,
                'date' => $movement->date->toDateString(),
                'amount' => (float) $movement->amount,
                'description' => $movement->description,
                'category_id' => $movement->category_id,
                'category' => $movement->category?->name,
                'business_line_id' => $movement->business_line_id,
                'business_line' => $movement->businessLine?->name,
                'invoice' => $movement->invoice ? ['id' => $movement->invoice->id, 'label' => $movement->invoice->displayNumber()] : null,
                'expense' => $movement->expense ? ['id' => $movement->expense->id, 'label' => trim(($movement->expense->supplier['name'] ?? '').' '.($movement->expense->number ?? ''))] : null,
                'transfer' => $movement->isTransfer(),
                'manual' => $movement->isManual(),
            ]),
            'receivables' => $receivables->invoices()->take(50)->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'label' => $invoice->displayNumber(),
                'customer' => $invoice->customer['name'] ?? '',
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'outstanding' => $invoice->outstanding(),
                'overdue' => $invoice->isOverdue(),
            ])->values(),
            'open' => $receivables->summary(),
            'dolibarr' => [
                'invoices' => $dolibarr->pending()->count(),
            ],
            'accountTypes' => FinancialAccountType::options(),
            'categories' => AccountingCategory::options(),
            'businessLines' => BusinessLine::options(),
            'canManage' => $request->user()->can(Permission::AccountingManage->value),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $account = FinancialAccount::query()->create([...$this->validatedAccount($request), 'sort' => (int) FinancialAccount::query()->max('sort') + 1]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conto :name creato.', ['name' => $account->name])]);

        return to_route('accounting.cash.index', ['account' => $account->id]);
    }

    public function updateAccount(Request $request, FinancialAccount $account): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $account->update($this->validatedAccount($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conto salvato.')]);

        return back();
    }

    public function destroyAccount(FinancialAccount $account): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);
        abort_if($account->movements()->exists(), 409, __('Il conto ha dei movimenti: disattivalo invece di eliminarlo.'));

        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conto eliminato.')]);

        return to_route('accounting.cash.index');
    }

    /**
     * Entry without a document: money in or out with a category (fees, taxes, contributions...).
     */
    public function storeMovement(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        AccountMovement::query()->create([...$this->validatedMovement($request), 'user_id' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movimento registrato.')]);

        return back();
    }

    public function updateMovement(Request $request, AccountMovement $movement): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);
        abort_unless($movement->isManual(), 409, __('Incassi, pagamenti e giroconti si modificano dal loro documento o si eliminano.'));

        $movement->update($this->validatedMovement($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movimento salvato.')]);

        return back();
    }

    /**
     * Manual entries, collections (the invoice goes back to be collected) and both sides of a transfer.
     * Payments of expenses are undone from the expense.
     */
    public function destroyMovement(AccountMovement $movement): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);
        abort_if($movement->expense_id !== null, 409, __('È il pagamento di una spesa: segnala da pagare dalla spesa.'));

        DB::transaction(function () use ($movement): void {
            $invoice = $movement->invoice;

            if ($movement->isTransfer()) {
                AccountMovement::query()->where('transfer_uuid', $movement->transfer_uuid)->delete();
            } else {
                $movement->delete();
            }

            $invoice?->refreshPayments();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movimento eliminato.')]);

        return back();
    }

    /**
     * Money moved between two accounts (e.g. bank → card): out of one, into the other.
     */
    public function transfer(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $data = $request->validate([
            'from_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', Rule::exists('financial_accounts', 'id')],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['from_account_id' => 'dal conto', 'to_account_id' => 'al conto', 'date' => 'data', 'amount' => 'importo']);

        DB::transaction(function () use ($data, $request): void {
            $uuid = (string) Str::uuid();
            $description = $data['description'] ?? __('Giroconto');

            foreach ([[$data['from_account_id'], -1], [$data['to_account_id'], 1]] as [$accountId, $sign]) {
                AccountMovement::query()->create([
                    'financial_account_id' => $accountId,
                    'date' => $data['date'],
                    'amount' => round($sign * (float) $data['amount'], 2),
                    'description' => $description,
                    'transfer_uuid' => $uuid,
                    'user_id' => $request->user()->id,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Giroconto registrato.')]);

        return back();
    }

    /**
     * Collection of an invoice (refund of a credit note) from its page.
     */
    public function collect(Request $request, Invoice $invoice, RecordInvoiceCollection $record): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ], [], ['financial_account_id' => 'conto', 'date' => 'data', 'amount' => 'importo']);

        $record->handle($invoice, (int) $data['financial_account_id'], $data['date'], (float) $data['amount'], $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => $invoice->fresh()->isPaid() ? __('Incasso registrato: fattura saldata.') : __('Incasso registrato.')]);

        return back();
    }

    /**
     * Collections written by the Dolibarr import in the notes of the invoices.
     */
    public function importDolibarr(Request $request, ImportDolibarrCollections $import): RedirectResponse
    {
        $this->authorize(Permission::AccountingManage->value);

        $data = $request->validate(['financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')]], [], ['financial_account_id' => 'conto']);
        $result = $import->handle((int) $data['financial_account_id'], $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':collections incassi recuperati da Dolibarr su :invoices fatture.', $result)]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAccount(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'iban' => ['nullable', 'string', 'max:34'],
            'opening_balance' => ['nullable', 'numeric', 'between:-99999999,99999999'],
            'opening_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'nome', 'opening_balance' => 'saldo iniziale', 'opening_date' => 'data del saldo iniziale']);

        return [
            ...$data,
            'iban' => filled($data['iban'] ?? null) ? strtoupper(str_replace(' ', '', $data['iban'])) : null,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedMovement(Request $request): array
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')],
            'date' => ['required', 'date'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('accounting_categories', 'id')],
            'business_line_id' => ['nullable', 'integer', Rule::exists('business_lines', 'id')],
        ], [], ['financial_account_id' => 'conto', 'date' => 'data', 'amount' => 'importo', 'category_id' => 'categoria']);

        return [
            'financial_account_id' => $data['financial_account_id'],
            'date' => $data['date'],
            'amount' => round(($data['direction'] === 'out' ? -1 : 1) * (float) $data['amount'], 2),
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'business_line_id' => $data['business_line_id'] ?? null,
        ];
    }
}
