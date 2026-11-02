<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use App\Models\PaymentTerm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentTermRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::SettingsManage->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'method' => ['required', Rule::in(array_keys(PaymentTerm::METHODS))],
            'days' => ['required', 'integer', 'between:0,365'],
            'end_of_month' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'method' => 'modalità', 'days' => 'giorni', 'description' => 'testo'];
    }
}
