<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for every notification shown in the notification center.
 *
 * Subclasses describe their content once (title, message, icon, color, action)
 * and get both the database payload and, if the user opted in, the email.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Whether the notification may also be delivered by email
     * (it is sent only if the user enabled email notifications).
     */
    protected bool $mailable = true;

    abstract public function title(): string;

    abstract public function message(): string;

    /**
     * Material Design Icon name (https://pictogrammers.com/library/mdi/).
     */
    public function icon(): string
    {
        return 'mdi-bell-outline';
    }

    /**
     * Vuetify color: primary, secondary, success, info, warning, error.
     */
    public function color(): string
    {
        return 'primary';
    }

    public function actionUrl(): ?string
    {
        return null;
    }

    public function actionLabel(): ?string
    {
        return null;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->mailable && $notifiable instanceof User && $notifiable->email_notifications) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting(__('Ciao :name,', ['name' => $notifiable->name ?? '']))
            ->line($this->message());

        if ($this->actionUrl()) {
            $mail->action($this->actionLabel() ?? __('Apri'), $this->actionUrl());
        }

        return $mail;
    }

    /**
     * Payload stored in the `notifications` table and rendered by the frontend.
     *
     * @return array{title: string, message: string, icon: string, color: string, action_url: string|null, action_label: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'icon' => $this->icon(),
            'color' => $this->color(),
            'action_url' => $this->actionUrl(),
            'action_label' => $this->actionLabel(),
        ];
    }

    /**
     * Store a stable, short type in the database instead of the FQCN.
     */
    public function databaseType(object $notifiable): string
    {
        return class_basename(static::class);
    }
}
