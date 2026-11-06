<?php

namespace App\Enums;

/**
 * Collection of an issued invoice (`Invoice::paymentStatus()`); drafts and credit notes have none.
 */
enum InvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Credited = 'credited';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Da incassare',
            self::Partial => 'Incassata in parte',
            self::Overdue => 'Scaduta',
            self::Paid => 'Pagata',
            self::Credited => 'Stornata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::Partial => 'info',
            self::Overdue => 'error',
            self::Paid => 'success',
            self::Credited => 'grey',
        };
    }
}
