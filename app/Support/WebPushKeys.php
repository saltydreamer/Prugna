<?php

namespace App\Support;

use Minishlink\WebPush\VAPID;

/**
 * VAPID keys of the push notifications: generated on first use and kept in the settings
 * (the private key encrypted), so no server configuration is needed.
 */
class WebPushKeys
{
    public function __construct(private Settings $settings) {}

    public function publicKey(): string
    {
        $this->ensure();

        return (string) $this->settings->get('push.vapid_public_key');
    }

    /**
     * Points the web push channel at the stored keys.
     */
    public function apply(): void
    {
        $this->ensure();

        config([
            'webpush.vapid.public_key' => $this->settings->get('push.vapid_public_key'),
            'webpush.vapid.private_key' => $this->settings->get('push.vapid_private_key'),
            'webpush.vapid.subject' => config('webpush.vapid.subject') ?: 'mailto:'.($this->settings->get('company.email') ?: 'noreply@'.parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]);
    }

    private function ensure(): void
    {
        if ($this->settings->get('push.vapid_public_key') && $this->settings->has('push.vapid_private_key')) {
            return;
        }

        $keys = VAPID::createVapidKeys();

        $this->settings->set([
            'push.vapid_public_key' => $keys['publicKey'],
            'push.vapid_private_key' => $keys['privateKey'],
        ]);
    }
}
