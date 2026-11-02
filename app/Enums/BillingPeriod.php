<?php

namespace App\Enums;

/**
 * Periodicity of recurring services (fees of contracts).
 */
enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensile',
            self::Quarterly => 'Trimestrale',
            self::Semiannual => 'Semestrale',
            self::Annual => 'Annuale',
        };
    }

    /**
     * Short suffix for prices: "€ 50,00 al mese".
     */
    public function perLabel(): string
    {
        return match ($this) {
            self::Monthly => 'al mese',
            self::Quarterly => 'al trimestre',
            self::Semiannual => 'al semestre',
            self::Annual => "all'anno",
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $period) => ['value' => $period->value, 'title' => $period->label()], self::cases());
    }
}
