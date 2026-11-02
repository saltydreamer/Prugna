<?php

namespace App\Http\Requests\Sales;

use App\Enums\InvoiceType;
use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OrderLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvoiceRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            ? $this->user()->can('update', $invoice)
            : $this->user()->can('create', Invoice::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $invoice = $this->route('invoice');
        $currentOrganization = $invoice instanceof Invoice ? $invoice->organization_id : null;

        return [
            'type' => $invoice instanceof Invoice
                ? ['exclude']
                : ['required', Rule::in([InvoiceType::Invoice->value, InvoiceType::Deposit->value])],
            // An archived customer stays valid on the drafts already addressed to it (e.g. credit notes).
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where(
                fn ($query) => $query->whereNull('deleted_at')->when($currentOrganization, fn ($query, int $id) => $query->orWhere('id', $id)),
            )],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $this->input('organization_id'))],
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'customer_reference' => ['nullable', 'string', 'max:20'],
            'customer_reference_date' => ['nullable', 'date'],
            'cig' => ['nullable', 'string', 'max:15'],
            'cup' => ['nullable', 'string', 'max:15'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'notes' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
            'split_payment' => ['boolean'],
            'apply_withholding' => ['boolean'],
            'apply_pension' => ['boolean'],
            ...$this->lineRules(),
            'lines.*.order_line_id' => ['nullable', 'integer'],
            'lines.*.system' => ['nullable', Rule::in([InvoiceLine::SYSTEM_DEPOSIT_DEDUCTION])],
            'lines.*.deducts_invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('type', InvoiceType::Deposit->value)],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireItemLine($validator),
            fn (Validator $validator) => $this->checkOrderQuantities($validator),
        ];
    }

    /**
     * Lines coming from an order can't invoice more than what is left on the order line.
     */
    private function checkOrderQuantities(Validator $validator): void
    {
        $invoice = $this->route('invoice');

        if (! $invoice instanceof Invoice || ! $invoice->order_id || $invoice->type !== InvoiceType::Invoice) {
            return;
        }

        $orderLines = OrderLine::query()->where('order_id', $invoice->order_id)->get()->keyBy('id');
        $current = $invoice->lines()->whereNotNull('order_line_id')->get()->groupBy('order_line_id')->map->sum('quantity');

        foreach ($this->input('lines', []) as $index => $line) {
            $id = $line['order_line_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $orderLine = $orderLines->get($id);

            if (! $orderLine) {
                $validator->errors()->add("lines.{$index}.order_line_id", __("La riga non appartiene all'ordine della fattura."));

                continue;
            }

            $available = round($orderLine->remainingQuantity() + (float) ($current[$id] ?? 0), 3);

            if ((float) ($line['quantity'] ?? 0) > $available) {
                $validator->errors()->add("lines.{$index}.quantity", __("Sull'ordine restano da fatturare :quantity unità.", ['quantity' => rtrim(rtrim(number_format($available, 3, ',', ''), '0'), ',')]));
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_id' => 'cliente',
            'contact_id' => 'contatto',
            'title' => 'oggetto',
            'issue_date' => 'data',
            'customer_reference' => 'riferimento ordine cliente',
            'customer_reference_date' => 'data riferimento',
            'payment_term_id' => 'condizione di pagamento',
            ...$this->lineAttributes(),
        ];
    }
}
