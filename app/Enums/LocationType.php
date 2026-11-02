<?php

namespace App\Enums;

enum LocationType: string
{
    case Registered = 'registered';
    case Operational = 'operational';
    case Administrative = 'administrative';
    case Warehouse = 'warehouse';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Sede legale',
            self::Operational => 'Sede operativa',
            self::Administrative => 'Sede amministrativa',
            self::Warehouse => 'Magazzino',
            self::Other => 'Altro',
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'title' => $type->label()], self::cases());
    }
}
