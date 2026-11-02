<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Company = 'company';
    case Freelancer = 'freelancer';
    case PublicBody = 'public_body';
    case NonProfit = 'non_profit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Azienda',
            self::Freelancer => 'Professionista',
            self::PublicBody => 'Ente pubblico',
            self::NonProfit => 'Associazione / Ente non profit',
            self::Other => 'Altro',
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
