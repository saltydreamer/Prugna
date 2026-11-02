<?php

namespace App\Http\Requests\Registry;

use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Customer created on the fly (e.g. from the quote editor): the organization
 * plus, optionally, its registered office and a contact person.
 */
class QuickOrganizationRequest extends OrganizationRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Organization::class);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $location = (array) $this->input('location', []);
        $location['country'] = Str::upper(trim((string) ($location['country'] ?? 'IT'))) ?: 'IT';
        $location['province'] = filled($location['province'] ?? null) ? Str::upper(trim($location['province'])) : null;

        $this->merge(['location' => $location]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasAddress = filled($this->input('location.address')) || filled($this->input('location.city'));
        $italian = $this->input('location.country') === 'IT';
        $phone = ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s().\/-]{5,32}$/'];

        return [
            ...parent::rules(),

            'location' => ['array'],
            'location.address' => [$hasAddress ? 'required' : 'nullable', 'string', 'max:255'],
            'location.city' => [$hasAddress ? 'required' : 'nullable', 'string', 'max:255'],
            'location.postal_code' => $hasAddress && $italian ? ['required', 'regex:/^\d{5}$/'] : ['nullable', 'string', 'max:16'],
            'location.province' => $hasAddress && $italian ? ['required', 'regex:/^[A-Z]{2}$/'] : ['nullable', 'string', 'max:8'],
            'location.country' => ['required', 'regex:/^[A-Z]{2}$/'],

            'contact' => ['nullable', 'array'],
            'contact.first_name' => ['nullable', 'required_with:contact.last_name,contact.email,contact.phone', 'string', 'max:255'],
            'contact.last_name' => ['nullable', 'required_with:contact.first_name,contact.email,contact.phone', 'string', 'max:255'],
            'contact.job_title' => ['nullable', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => $phone,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'location.postal_code.regex' => __('Il CAP è composto da 5 cifre.'),
            'location.province.regex' => __('Indica la sigla della provincia (es. MI).'),
            'contact.phone.regex' => __('Il numero di telefono non è valido.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'location.address' => 'indirizzo',
            'location.city' => 'città',
            'location.postal_code' => 'CAP',
            'location.province' => 'provincia',
            'location.country' => 'nazione',
            'contact.first_name' => 'nome',
            'contact.last_name' => 'cognome',
            'contact.email' => 'email del referente',
            'contact.phone' => 'telefono del referente',
        ];
    }
}
