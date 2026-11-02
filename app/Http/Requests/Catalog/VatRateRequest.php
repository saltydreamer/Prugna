<?php

namespace App\Http\Requests\Catalog;

use App\Enums\Permission;
use App\Models\VatRate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rates at 0% need the FatturaPA "natura" and the legal reference printed on invoices.
 */
class VatRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::CatalogSettings->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $exempt = (float) $this->input('rate') === 0.0;

        return [
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'nature' => $exempt ? ['required', Rule::in(array_keys(VatRate::NATURES))] : ['prohibited'],
            'legal_reference' => $exempt ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nature.required' => __('Per le operazioni senza IVA indica la natura (N1–N7) richiesta dalla fattura elettronica.'),
            'nature.prohibited' => __('La natura si indica solo per le aliquote allo 0%.'),
            'legal_reference.required' => __('Indica il riferimento normativo da riportare in fattura.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'rate' => 'aliquota',
            'nature' => 'natura',
            'legal_reference' => 'riferimento normativo',
        ];
    }
}
