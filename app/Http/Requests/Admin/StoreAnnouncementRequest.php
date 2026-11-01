<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Notifications\AnnouncementNotification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::NotificationsSend->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            'level' => ['required', Rule::in(AnnouncementNotification::LEVELS)],
            'action_url' => ['nullable', 'url', 'max:2048'],
            'audience' => ['required', Rule::in(['all', 'roles', 'users'])],
            'roles' => ['exclude_unless:audience,roles', 'required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'users' => ['exclude_unless:audience,users', 'required', 'array', 'min:1'],
            'users.*' => ['integer', Rule::exists('users', 'id')],
            'send_mail' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'titolo',
            'message' => 'messaggio',
            'level' => 'tipo',
            'action_url' => 'link',
            'audience' => 'destinatari',
            'roles' => 'ruoli',
            'users' => 'utenti',
        ];
    }
}
