<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Da evadere',
            self::InProgress => 'In lavorazione',
            self::Completed => 'Completato',
            self::Cancelled => 'Annullato',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'grey',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'mdi-clipboard-text-outline',
            self::InProgress => 'mdi-progress-wrench',
            self::Completed => 'mdi-clipboard-check-outline',
            self::Cancelled => 'mdi-cancel',
        };
    }

    /**
     * @return list<array{value: string, title: string, color: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'title' => $status->label(), 'color' => $status->color(), 'icon' => $status->icon()], self::cases());
    }
}
