<?php

namespace App\Http\Requests\Registry;

use App\Enums\LocationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('organization'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country' => Str::upper(trim((string) $this->input('country', 'IT'))) ?: 'IT',
            'province' => $this->filled('province') ? Str::upper(trim($this->input('province'))) : null,
        ]);
    }

    /**
     * Italian addresses require a 5-digit CAP and the 2-letter province code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $italian = $this->input('country') === 'IT';

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(LocationType::class)],
            'is_primary' => ['boolean'],
            'address' => ['required', 'string', 'max:255'],
            'postal_code' => $italian ? ['required', 'regex:/^\d{5}$/'] : ['nullable', 'string', 'max:16'],
            'city' => ['required', 'string', 'max:255'],
            'province' => $italian ? ['required', 'regex:/^[A-Z]{2}$/'] : ['nullable', 'string', 'max:8'],
            'country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s().\/-]{5,32}$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => __('Il CAP è composto da 5 cifre.'),
            'province.regex' => __('Indica la sigla della provincia (es. MI).'),
            'phone.regex' => __('Il numero di telefono non è valido.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'type' => 'tipo',
            'address' => 'indirizzo',
            'postal_code' => 'CAP',
            'city' => 'città',
            'province' => 'provincia',
            'country' => 'nazione',
            'phone' => 'telefono',
            'notes' => 'note',
        ];
    }
}
