<?php

namespace App\Notifications;

/**
 * Sent from the profile to check that notifications reach the phone.
 */
class PushTestNotification extends AppNotification
{
    protected bool $mailable = false;

    public function title(): string
    {
        return __('Notifiche attive');
    }

    public function message(): string
    {
        return __('Da ora ricevi qui gli avvisi dell\'app: esiti SDI, monte ore, attività dei preventivi.');
    }

    public function icon(): string
    {
        return 'mdi-cellphone-check';
    }

    public function color(): string
    {
        return 'success';
    }
}
