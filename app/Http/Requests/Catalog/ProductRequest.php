<?php

namespace App\Http\Requests\Catalog;

use App\Enums\BillingPeriod;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    /**
     * Codes are uppercase; only services can be recurring.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? Str::upper(trim($this->input('code'))) : null,
            'billing_period' => $this->input('type') === ProductType::Service->value ? ($this->input('billing_period') ?: null) : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'type' => ['required', Rule::enum(ProductType::class)],
            // Left empty, a code is generated (P00001, S00001…).
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._\/-]*$/', Rule::unique('products', 'code')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')],
            'unit' => ['required', Rule::enum(UnitOfMeasure::class)],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vat_rate_id' => ['required', 'integer', Rule::exists('vat_rates', 'id')->where('is_active', true)],
            'billing_period' => ['nullable', Rule::enum(BillingPeriod::class)],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('Il codice può contenere lettere, numeri e i caratteri . _ / -'),
            'vat_rate_id.exists' => __("Seleziona un'aliquota IVA attiva."),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'code' => 'codice',
            'name' => 'nome',
            'description' => 'descrizione',
            'category_id' => 'categoria',
            'unit' => 'unità di misura',
            'price' => 'prezzo',
            'cost' => 'costo',
            'vat_rate_id' => 'aliquota IVA',
            'billing_period' => 'periodicità',
            'notes' => 'note',
        ];
    }
}
