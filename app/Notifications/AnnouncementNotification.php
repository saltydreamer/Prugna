<?php

namespace App\Notifications;

/**
 * Free-form message sent by an administrator from the "Annunci" page.
 */
class AnnouncementNotification extends AppNotification
{
    public const LEVELS = ['info', 'success', 'warning', 'error'];

    public function __construct(
        public string $subject,
        public string $body,
        public string $level = 'info',
        public ?string $url = null,
        public bool $sendMail = false,
        public ?string $senderName = null,
    ) {
        $this->mailable = $sendMail;
    }

    public function title(): string
    {
        return $this->subject;
    }

    public function message(): string
    {
        return $this->body;
    }

    public function icon(): string
    {
        return match ($this->level) {
            'success' => 'mdi-check-circle-outline',
            'warning' => 'mdi-alert-outline',
            'error' => 'mdi-alert-octagon-outline',
            default => 'mdi-bullhorn-outline',
        };
    }

    public function color(): string
    {
        return $this->level;
    }

    public function actionUrl(): ?string
    {
        return $this->url;
    }

    public function actionLabel(): ?string
    {
        return $this->url ? __('Apri') : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            ...parent::toArray($notifiable),
            'sender' => $this->senderName,
        ];
    }
}
