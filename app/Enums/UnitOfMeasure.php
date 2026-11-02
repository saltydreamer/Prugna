<?php

namespace App\Enums;

/**
 * The value is the abbreviation printed on documents (FatturaPA "UnitaMisura").
 */
enum UnitOfMeasure: string
{
    case Piece = 'pz';
    case Hour = 'h';
    case Day = 'gg';
    case Month = 'mese';
    case Year = 'anno';
    case Lump = 'forfait';
    case Meter = 'm';
    case SquareMeter = 'm²';
    case Kilogram = 'kg';
    case Liter = 'l';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'Pezzi (pz)',
            self::Hour => 'Ore (h)',
            self::Day => 'Giorni (gg)',
            self::Month => 'Mesi',
            self::Year => 'Anni',
            self::Lump => 'A corpo (forfait)',
            self::Meter => 'Metri (m)',
            self::SquareMeter => 'Metri quadri (m²)',
            self::Kilogram => 'Chilogrammi (kg)',
            self::Liter => 'Litri (l)',
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $unit) => ['value' => $unit->value, 'title' => $unit->label()], self::cases());
    }
}
