<?php

namespace Tests\Feature\Registry;

use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FiscalCodeRulesTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function vatNumbers(): array
    {
        return [
            'valid' => ['01114601006', true],
            'valid with IT prefix and spaces' => ['IT 007 431 101 57', true],
            'wrong check digit' => ['01114601007', false],
            'too short' => ['0111460100', false],
            'foreign' => ['FR12345678901', true],
            'foreign malformed' => ['FR1', false],
        ];
    }

    #[DataProvider('vatNumbers')]
    public function test_vat_numbers(string $value, bool $valid): void
    {
        $this->assertSame($valid, $this->passes(new ItalianVatNumber, $value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function taxCodes(): array
    {
        return [
            'person' => ['RSSMRA80A01H501U', true],
            'person lowercase' => ['rssmra80a01h501u', true],
            'wrong check character' => ['RSSMRA80A01H501X', false],
            'company (11 digits)' => ['01114601006', true],
            'malformed' => ['RSSMRA80', false],
        ];
    }

    #[DataProvider('taxCodes')]
    public function test_tax_codes(string $value, bool $valid): void
    {
        $this->assertSame($valid, $this->passes(new ItalianTaxCode, $value));
    }

    private function passes(object $rule, string $value): bool
    {
        $passes = true;
        $rule->validate('field', $value, function () use (&$passes): void {
            $passes = false;
        });

        return $passes;
    }
}
