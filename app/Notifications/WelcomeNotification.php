<?php

namespace App\Notifications;

class WelcomeNotification extends AppNotification
{
    /**
     * Mail is handled by the verification / password reset emails.
     */
    protected bool $mailable = false;

    public function title(): string
    {
        return __('Benvenuto in :app!', ['app' => config('app.name')]);
    }

    public function message(): string
    {
        return __('Il tuo account è pronto. Completa il profilo e configura la sicurezza del tuo account.');
    }

    public function icon(): string
    {
        return 'mdi-hand-wave-outline';
    }

    public function color(): string
    {
        return 'success';
    }

    public function actionUrl(): ?string
    {
        return route('profile.show');
    }

    public function actionLabel(): ?string
    {
        return __('Vai al profilo');
    }
}
