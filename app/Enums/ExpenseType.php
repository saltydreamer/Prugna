<?php

namespace App\Enums;

enum ExpenseType: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Fattura',
            self::Receipt => 'Ricevuta / scontrino',
            self::CreditNote => 'Nota di credito',
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
