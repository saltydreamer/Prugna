<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Order;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PurchaseOrderRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $purchaseOrder = $this->route('purchase_order');

        return $purchaseOrder instanceof PurchaseOrder
            ? $this->user()->can('update', $purchaseOrder)
            : $this->user()->can('create', PurchaseOrder::class);
    }

    /**
     * Supplier, customer and lines are frozen once a cost has been charged back.
     */
    public function linesEditable(): bool
    {
        $purchaseOrder = $this->route('purchase_order');

        return ! $purchaseOrder instanceof PurchaseOrder || $purchaseOrder->isEditable();
    }

    /**
     * Costs of a customer order are charged to that order's customer.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('order_id') && ($order = Order::find($this->integer('order_id')))) {
            $this->merge(['customer_id' => $order->organization_id]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $purchaseOrder = $this->route('purchase_order');
        $supplierId = $this->linesEditable() ? $this->input('supplier_id') : $purchaseOrder->supplier_id;

        $header = [
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $supplierId)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'title' => ['nullable', 'string', 'max:255'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:64'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'notes' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ];

        if (! $this->linesEditable()) {
            return [...$header, 'supplier_id' => ['exclude'], 'customer_id' => ['exclude'], 'order_id' => ['exclude'], 'lines' => ['exclude']];
        }

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('organizations', 'id')->whereNull('deleted_at')->where('role', 'supplier')],
            'customer_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')],
            ...$header,
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
        return [function (Validator $validator): void {
            if ($this->linesEditable()) {
                $this->requireItemLine($validator);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'fornitore',
            'customer_id' => 'cliente',
            'order_id' => 'ordine cliente',
            'contact_id' => 'contatto',
            'owner_id' => 'responsabile',
            'title' => 'oggetto',
            'order_date' => 'data ordine',
            'expected_date' => 'consegna attesa',
            'supplier_reference' => 'riferimento fornitore',
            'payment_term_id' => 'condizione di pagamento',
            'lines.*.markup_percent' => 'ricarico',
            ...$this->lineAttributes(),
        ];
    }
}
