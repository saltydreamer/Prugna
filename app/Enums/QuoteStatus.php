<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Sent => 'Inviato',
            self::Accepted => 'Accettato',
            self::Rejected => 'Rifiutato',
            self::Expired => 'Scaduto',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'grey',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Rejected => 'error',
            self::Expired => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'mdi-pencil-outline',
            self::Sent => 'mdi-send-outline',
            self::Accepted => 'mdi-check-decagram-outline',
            self::Rejected => 'mdi-close-octagon-outline',
            self::Expired => 'mdi-timer-sand-complete',
        };
    }

    /**
     * Statuses that can go back to draft to be edited.
     */
    public function canReopen(): bool
    {
        return in_array($this, [self::Sent, self::Rejected, self::Expired], true);
    }

    /**
     * @return list<array{value: string, title: string, color: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => [
            'value' => $status->value,
            'title' => $status->label(),
            'color' => $status->color(),
            'icon' => $status->icon(),
        ], self::cases());
    }
}
