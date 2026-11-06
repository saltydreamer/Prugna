<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Bank = 'bank';
    case Card = 'card';
    case Cash = 'cash';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Conto corrente',
            self::Card => 'Carta',
            self::Cash => 'Contanti',
            self::Other => 'Altro',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Bank => 'mdi-bank-outline',
            self::Card => 'mdi-credit-card-outline',
            self::Cash => 'mdi-cash',
            self::Other => 'mdi-wallet-outline',
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
