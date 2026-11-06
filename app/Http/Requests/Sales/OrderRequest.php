<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Order;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrderRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');

        return $order instanceof Order
            ? $this->user()->can('update', $order)
            : $this->user()->can('create', Order::class);
    }

    /**
     * Customer and lines are frozen once something has been invoiced.
     */
    public function linesEditable(): bool
    {
        $order = $this->route('order');

        return ! $order instanceof Order || $order->isEditable();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $header = [
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $this->organizationId())],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'title' => ['nullable', 'string', 'max:255'],
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date'],
            'customer_reference' => ['nullable', 'string', 'max:20'],
            'customer_reference_date' => ['nullable', 'date'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'notes' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ];

        if (! $this->linesEditable()) {
            return [...$header, 'organization_id' => ['exclude'], 'lines' => ['exclude']];
        }

        return [
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->whereNull('deleted_at')->where('role', 'customer')],
            ...$header,
            ...$this->lineRules(),
            'lines.*.is_package' => ['boolean'],
            'lines.*.package_hours' => ['exclude_unless:lines.*.type,item', 'required_if_accepted:lines.*.is_package', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.package_months' => ['exclude_unless:lines.*.type,item', 'nullable', 'integer', 'between:1,120'],
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
            'organization_id' => 'cliente',
            'contact_id' => 'contatto',
            'owner_id' => 'responsabile',
            'title' => 'oggetto',
            'order_date' => 'data ordine',
            'delivery_date' => 'consegna prevista',
            'customer_reference' => 'riferimento cliente',
            'customer_reference_date' => 'data riferimento',
            'payment_term_id' => 'condizione di pagamento',
            ...$this->lineAttributes(),
            'lines.*.package_hours' => 'ore del pacchetto',
            'lines.*.package_months' => 'validità del pacchetto',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.package_hours.required_if_accepted' => __('Indica le ore del pacchetto.'),
        ];
    }

    private function organizationId(): mixed
    {
        $order = $this->route('order');

        return $this->linesEditable() ? $this->input('organization_id') : $order->organization_id;
    }
}
