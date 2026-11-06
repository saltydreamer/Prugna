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
     * Codes are uppercase; only services can be recurring or hour packages (never both).
     */
    protected function prepareForValidation(): void
    {
        $isService = $this->input('type') === ProductType::Service->value;
        $hourPackage = $isService && $this->boolean('hour_package');

        $this->merge([
            'code' => $this->filled('code') ? Str::upper(trim($this->input('code'))) : null,
            'billing_period' => $isService && ! $hourPackage ? ($this->input('billing_period') ?: null) : null,
            'hour_package' => $hourPackage,
            'package_months' => $hourPackage ? ($this->input('package_months') ?: null) : null,
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
            'business_line_id' => ['nullable', 'integer', Rule::exists('business_lines', 'id')],
            'unit' => ['required', Rule::enum(UnitOfMeasure::class), Rule::when($this->boolean('hour_package'), [Rule::in([UnitOfMeasure::Hour->value])])],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vat_rate_id' => ['required', 'integer', Rule::exists('vat_rates', 'id')->where('is_active', true)],
            'billing_period' => ['nullable', Rule::enum(BillingPeriod::class)],
            'hour_package' => ['boolean'],
            'package_months' => ['nullable', 'integer', 'between:1,120'],
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
            'unit.in' => __('Un monte ore si vende a ore: scegli l\'unità di misura «Ore (h)».'),
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
            'business_line_id' => 'linea di attività',
            'unit' => 'unità di misura',
            'price' => 'prezzo',
            'cost' => 'costo',
            'vat_rate_id' => 'aliquota IVA',
            'billing_period' => 'periodicità',
            'package_months' => 'validità del monte ore',
            'notes' => 'note',
        ];
    }
}
