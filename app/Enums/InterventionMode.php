<?php

namespace App\Enums;

enum InterventionMode: string
{
    case Remote = 'remote';
    case Onsite = 'onsite';

    public function label(): string
    {
        return match ($this) {
            self::Remote => 'Da remoto',
            self::Onsite => 'On site',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Remote => 'mdi-laptop',
            self::Onsite => 'mdi-map-marker-outline',
        };
    }

    /**
     * @return list<array{value: string, title: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode) => ['value' => $mode->value, 'title' => $mode->label(), 'icon' => $mode->icon()], self::cases());
    }
}
