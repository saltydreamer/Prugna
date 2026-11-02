<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case Deposit = 'deposit';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Fattura',
            self::Deposit => 'Fattura di acconto',
            self::CreditNote => 'Nota di credito',
        };
    }

    /**
     * FatturaPA TipoDocumento.
     */
    public function documentType(): string
    {
        return match ($this) {
            self::Invoice => 'TD01',
            self::Deposit => 'TD02',
            self::CreditNote => 'TD04',
        };
    }

    /**
     * Numbering series: invoices and deposits share FT, credit notes have NC.
     */
    public function series(): string
    {
        return $this === self::CreditNote ? 'NC' : 'FT';
    }
}
