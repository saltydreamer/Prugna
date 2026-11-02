<?php

namespace App\Http\Requests\Catalog;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An agreed price is either a fixed price or a discount percentage, never both.
 */
class CustomerPriceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ProductsUpdate->value);
    }

    protected function prepareForValidation(): void
    {
        $mode = $this->input('mode', $this->filled('discount_percent') ? 'discount' : 'price');

        $this->merge([
            'mode' => $mode,
            'price' => $mode === 'price' ? $this->input('price') : null,
            'discount_percent' => $mode === 'discount' ? $this->input('discount_percent') : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $customerPrice = $this->route('customerPrice');

        return [
            'organization_id' => [
                Rule::requiredIf($customerPrice === null), 'integer',
                Rule::exists('organizations', 'id')->whereNull('deleted_at'),
                Rule::unique('customer_prices', 'organization_id')
                    ->where('product_id', $customerPrice?->product_id ?? $this->input('product_id'))
                    ->ignore($customerPrice?->id),
            ],
            'product_id' => [Rule::requiredIf($customerPrice === null), 'integer', Rule::exists('products', 'id')],
            'mode' => ['required', Rule::in(['price', 'discount'])],
            'price' => ['required_if:mode,price', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'discount_percent' => ['required_if:mode,discount', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organization_id.unique' => __('Per questo cliente esiste già un prezzo concordato su questo articolo: modificalo.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_id' => 'cliente',
            'product_id' => 'articolo',
            'price' => 'prezzo',
            'discount_percent' => 'sconto',
            'valid_until' => 'valido fino al',
            'notes' => 'note',
        ];
    }
}
