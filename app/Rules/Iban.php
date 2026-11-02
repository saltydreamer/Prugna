<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IBAN with ISO 13616 check digits (mod 97).
 */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid(self::normalize((string) $value))) {
            $fail(__("L'IBAN non è valido."));
        }
    }

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    public static function isValid(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) || (str_starts_with($iban, 'IT') && strlen($iban) !== 27)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn (array $match) => (string) (ord($match[0]) - 55), $rearranged);

        $remainder = 0;

        foreach (str_split((string) $numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }
}
