<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * VAT number: an Italian Partita IVA (11 digits, optional "IT" prefix) with a valid
 * check digit, or a foreign VAT number with its two-letter country prefix.
 * Use self::normalize() before validating and storing the value.
 */
class ItalianVatNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = self::normalize((string) $value);

        if (preg_match('/^[A-Z]{2}/', $value)) {
            if (! preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $value)) {
                $fail(__('La partita IVA estera non è valida.'));
            }

            return;
        }

        if (! self::isValidPartitaIva($value)) {
            $fail(__('La partita IVA non è valida.'));
        }
    }

    /**
     * Uppercase, without spaces/dots and without the "IT" prefix for Italian numbers.
     */
    public static function normalize(string $value): string
    {
        $value = strtoupper((string) preg_replace('/[\s.\-]/', '', $value));

        return preg_match('/^IT\d{11}$/', $value) ? substr($value, 2) : $value;
    }

    public static function isValidPartitaIva(string $value): bool
    {
        if (! preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $value[$i];

            if ($i % 2 === 1) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
        }

        return (10 - $sum % 10) % 10 === (int) $value[10];
    }
}
