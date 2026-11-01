<?php

namespace App\Notifications;

class PasswordChangedNotification extends AppNotification
{
    public function title(): string
    {
        return __('Password modificata');
    }

    public function message(): string
    {
        return __('La password del tuo account è stata modificata. Se non sei stato tu, reimpostala subito e contatta un amministratore.');
    }

    public function icon(): string
    {
        return 'mdi-lock-reset';
    }

    public function color(): string
    {
        return 'warning';
    }

    public function actionUrl(): ?string
    {
        return route('profile.show', ['tab' => 'security']);
    }

    public function actionLabel(): ?string
    {
        return __('Sicurezza account');
    }
}
