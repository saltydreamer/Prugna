<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use App\Rules\Iban;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CompanySettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::SettingsManage->value);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vat_number' => $this->filled('vat_number') ? ItalianVatNumber::normalize($this->input('vat_number')) : null,
            'tax_code' => $this->filled('tax_code') ? ItalianTaxCode::normalize($this->input('tax_code')) : null,
            'iban' => $this->filled('iban') ? Iban::normalize($this->input('iban')) : null,
            'province' => $this->filled('province') ? Str::upper(trim($this->input('province'))) : null,
            'country' => Str::upper(trim((string) $this->input('country', 'IT'))) ?: 'IT',
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $phone = ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s().\/-]{5,32}$/'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', new ItalianVatNumber],
            'tax_code' => ['nullable', 'string', new ItalianTaxCode],
            'rea' => ['nullable', 'string', 'max:32'],
            'share_capital' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:8'],
            'country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'pec' => ['nullable', 'email', 'max:255'],
            'phone' => $phone,
            'website' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', new Iban],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'ragione sociale',
            'vat_number' => 'partita IVA',
            'tax_code' => 'codice fiscale',
            'rea' => 'numero REA',
            'share_capital' => 'capitale sociale',
            'address' => 'indirizzo',
            'postal_code' => 'CAP',
            'city' => 'città',
            'province' => 'provincia',
            'country' => 'nazione',
            'pec' => 'PEC',
            'phone' => 'telefono',
            'website' => 'sito web',
            'bank_name' => 'banca',
            'iban' => 'IBAN',
        ];
    }
}
