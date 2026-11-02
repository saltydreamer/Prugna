<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Issued => 'Emessa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'grey',
            self::Issued => 'success',
        };
    }
}
