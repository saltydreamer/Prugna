<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Materials = 'materials';
    case Services = 'services';
    case Travel = 'travel';
    case Fuel = 'fuel';
    case Utilities = 'utilities';
    case Rent = 'rent';
    case Software = 'software';
    case Consulting = 'consulting';
    case Equipment = 'equipment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Materials => 'Materiale',
            self::Services => 'Servizi',
            self::Travel => 'Trasferte, vitto e alloggio',
            self::Fuel => 'Carburante e auto',
            self::Utilities => 'Utenze e telefonia',
            self::Rent => 'Affitti e noleggi',
            self::Software => 'Software e abbonamenti',
            self::Consulting => 'Consulenze',
            self::Equipment => 'Attrezzature',
            self::Other => 'Altro',
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'title' => $category->label()], self::cases());
    }
}
