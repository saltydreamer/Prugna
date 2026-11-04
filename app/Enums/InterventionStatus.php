<?php

namespace App\Enums;

/**
 * Derived from the intervention: never stored.
 */
enum InterventionStatus: string
{
    case Running = 'running';
    case ToInvoice = 'to_invoice';
    case Invoiced = 'invoiced';
    case NotBillable = 'not_billable';
    case Covered = 'covered';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'In corso',
            self::ToInvoice => 'Da fatturare',
            self::Invoiced => 'Fatturato',
            self::NotBillable => 'Non fatturabile',
            self::Covered => 'Coperto dal monte ore',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::ToInvoice => 'warning',
            self::Invoiced => 'success',
            self::NotBillable => 'grey',
            self::Covered => 'secondary',
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'title' => $status->label()], self::cases());
    }
}
