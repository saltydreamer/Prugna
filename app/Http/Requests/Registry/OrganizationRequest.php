<?php

namespace App\Http\Requests\Registry;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            ? $this->user()->can('update', $organization)
            : $this->user()->can('create', Organization::class);
    }

    /**
     * Normalize codes and URLs before validation, so that they are stored consistently.
     */
    protected function prepareForValidation(): void
    {
        $website = trim((string) $this->input('website'));

        $this->merge([
            'vat_number' => $this->filled('vat_number') ? ItalianVatNumber::normalize($this->input('vat_number')) : null,
            'tax_code' => $this->filled('tax_code') ? ItalianTaxCode::normalize($this->input('tax_code')) : null,
            'sdi_code' => $this->filled('sdi_code') ? Str::upper(trim($this->input('sdi_code'))) : null,
            'website' => $website !== '' && ! preg_match('#^https?://#i', $website) ? "https://{$website}" : ($website ?: null),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'vat_number' => [
                'nullable', 'string', 'max:32', new ItalianVatNumber,
                Rule::unique('organizations', 'vat_number')->ignore($organization?->id),
            ],
            'tax_code' => ['nullable', 'string', 'max:32', new ItalianTaxCode],
            // 7 characters for businesses, 6 for the Public Administration ("codice univoco ufficio").
            'sdi_code' => ['nullable', $this->input('type') === OrganizationType::PublicBody->value ? 'regex:/^[A-Z0-9]{6}$/' : 'regex:/^[A-Z0-9]{7}$/'],
            'split_payment' => ['boolean'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'email' => ['nullable', 'email', 'max:255'],
            'pec' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s().\/-]{5,32}$/'],
            'website' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vat_number.unique' => __("Esiste già un'organizzazione con questa partita IVA (controlla anche tra le archiviate)."),
            'sdi_code.regex' => $this->input('type') === OrganizationType::PublicBody->value
                ? __('Il codice univoco ufficio della PA è composto da 6 caratteri.')
                : __('Il codice destinatario SDI è composto da 7 caratteri alfanumerici.'),
            'phone.regex' => __('Il numero di telefono non è valido.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'ragione sociale',
            'type' => 'tipo',
            'vat_number' => 'partita IVA',
            'tax_code' => 'codice fiscale',
            'sdi_code' => 'codice SDI',
            'payment_term_id' => 'condizione di pagamento',
            'pec' => 'PEC',
            'phone' => 'telefono',
            'website' => 'sito web',
            'notes' => 'note',
        ];
    }
}
