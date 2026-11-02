<?php

namespace App\Actions\Quotes;

use App\Models\QuoteLine;

/**
 * Totals of a quote, Italian style: each line is rounded to the cent, VAT is computed
 * on the summary per rate ("riepilogo IVA"). Optional lines count only when selected.
 * resources/js/utils/quoteTotals.js mirrors this logic for the live editor.
 */
class QuoteTotals
{
    public static function lineTotal(float $quantity, float $unitPrice, float $discountPercent = 0): float
    {
        return round($quantity * $unitPrice * (1 - $discountPercent / 100), 2);
    }

    /**
     * @param  iterable<QuoteLine>  $lines
     * @return array{subtotal: float, vat_total: float, total: float, optional_total: float, vat_summary: list<array{rate: float, nature: string|null, taxable: float, vat: float}>}
     */
    public static function summarize(iterable $lines): array
    {
        $groups = [];
        $optionalTotal = 0.0;

        foreach ($lines as $line) {
            if (! $line->isItem()) {
                continue;
            }

            $total = self::lineTotal((float) $line->quantity, (float) $line->unit_price, (float) $line->discount_percent);

            if (! $line->isIncluded()) {
                $optionalTotal += $total;

                continue;
            }

            $key = $line->vat_rate.'|'.$line->vat_nature;
            $groups[$key] ??= ['rate' => (float) $line->vat_rate, 'nature' => $line->vat_nature, 'taxable' => 0.0, 'vat' => 0.0];
            $groups[$key]['taxable'] += $total;
        }

        $summary = [];

        foreach ($groups as $group) {
            $group['taxable'] = round($group['taxable'], 2);
            $group['vat'] = round($group['taxable'] * $group['rate'] / 100, 2);
            $summary[] = $group;
        }

        usort($summary, fn (array $a, array $b) => $b['rate'] <=> $a['rate']);

        $subtotal = round(array_sum(array_column($summary, 'taxable')), 2);
        $vatTotal = round(array_sum(array_column($summary, 'vat')), 2);

        return [
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'total' => round($subtotal + $vatTotal, 2),
            'optional_total' => round($optionalTotal, 2),
            'vat_summary' => $summary,
        ];
    }
}
