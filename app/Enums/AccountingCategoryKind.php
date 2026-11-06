<?php

namespace App\Enums;

/**
 * What a category counts as in the profit and loss account.
 */
enum AccountingCategoryKind: string
{
    case Cost = 'cost';
    case Revenue = 'revenue';
    case Tax = 'tax';
    case Contribution = 'contribution';

    public function label(): string
    {
        return match ($this) {
            self::Cost => 'Costo',
            self::Revenue => 'Ricavo',
            self::Tax => 'Imposte',
            self::Contribution => 'Contributi previdenziali',
        };
    }

    /**
     * Money going out for this kind (costs, taxes, contributions) or coming in (revenue).
     */
    public function isOutflow(): bool
    {
        return $this !== self::Revenue;
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind) => ['value' => $kind->value, 'title' => $kind->label()], self::cases());
    }
}
