<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends StoreUserRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            ...parent::rules(),
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
        ];
    }

    /**
     * Prevent admins from locking themselves (or the application) out.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $user */
                $user = $this->route('user');
                $isSelf = $this->user()->is($user);

                if ($isSelf && ! $this->boolean('is_active', true)) {
                    $validator->errors()->add('is_active', __('Non puoi disattivare il tuo account.'));
                }

                $removesSuperAdmin = $user->isSuperAdmin()
                    && ! in_array(Role::SuperAdmin->value, $this->input('roles', []), true);

                if ($removesSuperAdmin && User::role(Role::SuperAdmin->value)->count() <= 1) {
                    $validator->errors()->add('roles', __('Deve esistere almeno un super amministratore.'));
                }
            },
        ];
    }
}
