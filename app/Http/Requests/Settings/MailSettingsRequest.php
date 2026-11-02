<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailSettingsRequest extends FormRequest
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
        $enabled = $this->boolean('enabled');

        return [
            'enabled' => ['boolean'],
            'host' => [$enabled ? 'required' : 'nullable', 'string', 'max:255'],
            'port' => [$enabled ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            // Left empty, the saved password is kept.
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => [$enabled ? 'required' : 'nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'host' => 'server SMTP',
            'port' => 'porta',
            'encryption' => 'sicurezza',
            'username' => 'utente',
            'from_address' => 'indirizzo mittente',
            'from_name' => 'nome mittente',
        ];
    }
}
