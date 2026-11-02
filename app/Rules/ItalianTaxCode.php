<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Codice fiscale: 16 characters for people (with check character) or
 * 11 digits for companies (same algorithm as the Partita IVA).
 */
class ItalianTaxCode implements ValidationRule
{
    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = self::normalize((string) $value);

        $valid = strlen($value) === 11
            ? ItalianVatNumber::isValidPartitaIva($value)
            : self::isValidPersonalCode($value);

        if (! $valid) {
            $fail(__('Il codice fiscale non è valido.'));
        }
    }

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    public static function isValidPersonalCode(string $value): bool
    {
        // Letters in numeric positions are allowed (omocodia).
        if (! preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $value)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 15; $i++) {
            $char = $value[$i];

            $sum += $i % 2 === 0
                ? self::ODD[$char]
                : (ctype_digit($char) ? (int) $char : ord($char) - ord('A'));
        }

        return chr(ord('A') + $sum % 26) === $value[15];
    }
}
