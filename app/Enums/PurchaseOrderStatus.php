<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Sent => 'Inviato',
            self::Confirmed => 'Confermato',
            self::Received => 'Ricevuto',
            self::Cancelled => 'Annullato',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'grey',
            self::Sent => 'info',
            self::Confirmed => 'warning',
            self::Received => 'success',
            self::Cancelled => 'grey',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'mdi-file-document-edit-outline',
            self::Sent => 'mdi-send-outline',
            self::Confirmed => 'mdi-handshake-outline',
            self::Received => 'mdi-package-variant-closed-check',
            self::Cancelled => 'mdi-cancel',
        };
    }

    /**
     * Costs of these orders are real and can be charged back to the customer.
     */
    public function isCommitted(): bool
    {
        return in_array($this, [self::Sent, self::Confirmed, self::Received], true);
    }

    /**
     * @return list<array{value: string, title: string, color: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'title' => $status->label(), 'color' => $status->color(), 'icon' => $status->icon()], self::cases());
    }
}
