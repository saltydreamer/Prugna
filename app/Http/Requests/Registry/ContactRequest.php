<?php

namespace App\Http\Requests\Registry;

use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('organization'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');
        $phone = ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s().\/-]{5,32}$/'];

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => $phone,
            'mobile' => $phone,
            'is_primary' => ['boolean'],
            // The location must belong to the same organization.
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('organization_id', $organization->id)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Il numero di telefono non è valido.'),
            'mobile.regex' => __('Il numero di cellulare non è valido.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => 'nome',
            'last_name' => 'cognome',
            'job_title' => 'ruolo',
            'phone' => 'telefono',
            'mobile' => 'cellulare',
            'location_id' => 'sede',
            'notes' => 'note',
        ];
    }
}
