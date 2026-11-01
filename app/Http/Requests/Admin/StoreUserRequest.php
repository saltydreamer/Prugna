<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
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
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => [Rule::requiredIf(fn (): bool => ! $this->boolean('send_reset_link')), 'nullable', 'string', Password::default(), 'confirmed'],
            'send_reset_link' => ['boolean'],
            'is_active' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name'), $this->superAdminRoleRule()],
        ];
    }

    /**
     * Only a super admin can grant the super-admin role.
     */
    protected function superAdminRoleRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === Role::SuperAdmin->value && ! $this->user()->isSuperAdmin()) {
                $fail(__('Solo un super amministratore può assegnare questo ruolo.'));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'roles' => 'ruoli',
            'roles.*' => 'ruolo',
        ];
    }
}
