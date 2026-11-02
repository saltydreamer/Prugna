<?php

namespace App\Enums;

/**
 * How an expense is (or will be) paid.
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cash = 'cash';
    case DirectDebit = 'direct_debit';
    case Riba = 'riba';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bonifico',
            self::Card => 'Carta',
            self::Cash => 'Contanti',
            self::DirectDebit => 'Addebito diretto (SDD/RID)',
            self::Riba => 'Ri.Ba.',
            self::Other => 'Altro',
        };
    }

    /**
     * Paid on the spot: the expense is recorded as already paid.
     */
    public function isImmediate(): bool
    {
        return in_array($this, [self::Card, self::Cash], true);
    }

    /**
     * From a FatturaPA ModalitaPagamento code.
     */
    public static function fromFatturaPa(?string $code): ?self
    {
        return match ($code) {
            'MP01' => self::Cash,
            'MP05', 'MP19', 'MP21' => self::BankTransfer,
            'MP08' => self::Card,
            'MP09', 'MP10', 'MP11', 'MP16', 'MP20' => self::DirectDebit,
            'MP12' => self::Riba,
            null, '' => null,
            default => self::Other,
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'title' => $method->label()], self::cases());
    }
}
