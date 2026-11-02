<?php

namespace App\Http\Requests\Purchases;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseType;
use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Expense;
use App\Support\Ai\DocumentReader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExpenseRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense
            ? $this->user()->can('update', $expense)
            : $this->user()->can('create', Expense::class);
    }

    /**
     * Supplier, customer and lines are frozen once a cost has been charged back.
     */
    public function linesEditable(): bool
    {
        $expense = $this->route('expense');

        return ! $expense instanceof Expense || $expense->isEditable();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $expense = $this->route('expense');

        $header = [
            'type' => ['required', Rule::enum(ExpenseType::class)],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'number' => ['nullable', 'string', 'max:64'],
            'document_date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'due_date' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:20480', 'mimes:'.implode(',', DocumentReader::MIMES)],
            'ai_extraction_id' => [$expense instanceof Expense ? 'exclude' : 'nullable', 'integer', Rule::exists('ai_extractions', 'id')],
        ];

        if (! $this->linesEditable()) {
            return [...$header, 'supplier_id' => ['exclude'], 'customer_id' => ['exclude'], 'order_id' => ['exclude'], 'lines' => ['exclude']];
        }

        return [
            ...$header,
            'supplier_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('role', 'supplier')],
            'customer_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')],
            ...$this->lineRules(),
            'lines.*.rebill' => ['boolean'],
            'lines.*.markup_percent' => ['exclude_unless:lines.*.type,item', 'nullable', 'numeric', 'between:0,1000'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->linesEditable()) {
                    $this->requireItemLine($validator);
                }
            },
            // The same supplier document can't be recorded twice.
            function (Validator $validator): void {
                $supplierId = $this->linesEditable() ? $this->input('supplier_id') : $this->route('expense')?->supplier_id;

                if (blank($this->input('number')) || blank($supplierId) || blank($this->input('document_date'))) {
                    return;
                }

                $duplicate = Expense::query()
                    ->where('supplier_id', $supplierId)
                    ->where('number', $this->input('number'))
                    ->whereYear('document_date', substr((string) $this->input('document_date'), 0, 4))
                    ->when($this->route('expense'), fn ($query, Expense $expense) => $query->whereKeyNot($expense->id))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('number', __('Questo documento del fornitore è già registrato.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'category' => 'categoria',
            'supplier_id' => 'fornitore',
            'customer_id' => 'cliente',
            'order_id' => 'ordine cliente',
            'number' => 'numero',
            'document_date' => 'data',
            'title' => 'descrizione',
            'payment_method' => 'pagamento',
            'due_date' => 'scadenza',
            'paid_at' => 'data di pagamento',
            'attachment' => 'documento',
            'lines.*.markup_percent' => 'ricarico',
            ...$this->lineAttributes(),
        ];
    }
}
