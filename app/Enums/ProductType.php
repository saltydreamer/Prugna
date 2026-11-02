<?php

namespace App\Enums;

enum ProductType: string
{
    case Product = 'product';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Prodotto',
            self::Service => 'Servizio',
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
