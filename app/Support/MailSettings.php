<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Applies the SMTP server configured from the UI (Impostazioni → Email) on top of config/mail.php.
 * Called when the mail manager is first resolved, so requests that send no email never read it.
 */
class MailSettings
{
    public function __construct(private Settings $settings, private Repository $config) {}

    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('mail.enabled')
            && filled($this->settings->get('mail.host'))
            && filled($this->settings->get('mail.from_address'));
    }

    /**
     * Whether emails really leave the server (not only written to the log).
     */
    public function canSend(): bool
    {
        return $this->isConfigured() || config('mail.default') !== 'log';
    }

    public function apply(): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $encryption = $this->settings->get('mail.encryption');

        $this->config->set('mail.default', 'smtp');
        $this->config->set('mail.mailers.smtp', [
            ...(array) $this->config->get('mail.mailers.smtp', []),
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->settings->get('mail.host'),
            'port' => (int) $this->settings->get('mail.port'),
            'username' => $this->settings->get('mail.username'),
            'password' => $this->settings->get('mail.password'),
            'timeout' => 20,
        ]);
        $this->config->set('mail.from', [
            'address' => $this->settings->get('mail.from_address'),
            'name' => $this->settings->get('mail.from_name') ?: $this->settings->get('company.name') ?: config('app.name'),
        ]);
    }
}
