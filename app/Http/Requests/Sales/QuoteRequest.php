<?php

namespace App\Http\Requests\Sales;

use App\Enums\Permission;
use App\Enums\QuoteLineType;
use App\Models\Quote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote
            ? $this->user()->can('update', $quote)
            : $this->user()->can('create', Quote::class);
    }

    /**
     * Without the "supervise" permission a salesperson is always the owner;
     * supervisors may assign the quote, defaulting to themselves.
     */
    protected function prepareForValidation(): void
    {
        $quote = $this->route('quote');
        $currentOwner = $quote instanceof Quote ? $quote->owner_id : $this->user()->id;

        if (! $this->user()->can(Permission::QuotesSupervise->value)) {
            $this->merge(['owner_id' => $currentOwner]);
        } elseif (! $this->filled('owner_id')) {
            $this->merge(['owner_id' => $currentOwner ?? $this->user()->id]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->whereNull('deleted_at')->where('role', 'customer')],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $this->input('organization_id'))],
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:issue_date'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'intro' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],

            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.type' => ['required', Rule::enum(QuoteLineType::class)],
            'lines.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'lines.*.code' => ['nullable', 'string', 'max:64'],
            'lines.*.description' => ['required', 'string', 'max:5000'],
            'lines.*.unit' => ['nullable', 'string', 'max:16'],
            'lines.*.quantity' => ['exclude_unless:lines.*.type,item', 'required', 'numeric', 'gt:0', 'max:9999999'],
            'lines.*.unit_price' => ['exclude_unless:lines.*.type,item', 'required', 'numeric', 'between:-99999999,99999999'],
            'lines.*.discount_percent' => ['exclude_unless:lines.*.type,item', 'nullable', 'numeric', 'between:0,100'],
            'lines.*.vat_rate_id' => ['exclude_unless:lines.*.type,item', 'required', 'integer', Rule::exists('vat_rates', 'id')],
            'lines.*.is_optional' => ['boolean'],
            'lines.*.is_selected' => ['boolean'],
            'lines.*.is_package' => ['boolean'],
            'lines.*.package_hours' => ['exclude_unless:lines.*.type,item', 'required_if_accepted:lines.*.is_package', 'nullable', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.package_months' => ['exclude_unless:lines.*.type,item', 'nullable', 'integer', 'between:1,120'],
        ];
    }

    /**
     * At least one priced line.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $items = collect($this->input('lines', []))->where('type', QuoteLineType::Item->value);

                if ($items->isEmpty()) {
                    $validator->errors()->add('lines', __('Aggiungi almeno una riga con un articolo o un servizio.'));
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
            'organization_id' => 'cliente',
            'contact_id' => 'contatto',
            'owner_id' => 'responsabile',
            'title' => 'oggetto',
            'issue_date' => 'data',
            'valid_until' => 'valido fino al',
            'payment_term_id' => 'condizione di pagamento',
            'intro' => 'introduzione',
            'terms' => 'condizioni',
            'lines' => 'righe',
            'lines.*.description' => 'descrizione',
            'lines.*.quantity' => 'quantità',
            'lines.*.unit_price' => 'prezzo',
            'lines.*.discount_percent' => 'sconto',
            'lines.*.vat_rate_id' => 'IVA',
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
}
