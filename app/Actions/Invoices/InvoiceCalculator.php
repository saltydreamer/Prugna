<?php

namespace App\Actions\Invoices;

use App\Actions\Documents\DocumentTotals;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Fiscal\FiscalCodes;
use Illuminate\Support\Collection;

/**
 * Fiscal totals of an invoice:
 * - lines rounded to the cent, VAT on the summary per rate (as quotes);
 * - pension fund ("cassa") on the taxable of the lines (stamp duty excluded), added to the summary of its VAT rate;
 * - withholding tax on the taxable (+ pension fund when subject), subtracted from the amount due;
 * - split payment: VAT shown but paid by the customer to the State, not to us;
 * - virtual stamp duty (2 €) when the operations without VAT exceed 77.47 €.
 */
class InvoiceCalculator
{
    /**
     * Natures of operations subject to stamp duty (no VAT applied).
     */
    private const STAMP_DUTY_NATURES = ['N1', 'N2', 'N2.1', 'N2.2', 'N3', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4'];

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array{subtotal: float, pension_base: float, pension_amount: float, vat_total: float, total: float, withholding_amount: float, amount_due: float, vat_summary: list<array{rate: float, nature: string|null, taxable: float, vat: float}>}
     */
    public function calculate(Invoice $invoice, Collection $lines): array
    {
        $items = $lines->filter(fn (InvoiceLine $line) => $line->isItem());

        $groups = [];
        $subtotal = 0.0;
        $base = 0.0;

        foreach ($items as $line) {
            $total = DocumentTotals::lineTotal((float) $line->quantity, (float) $line->unit_price, (float) $line->discount_percent);
            $subtotal += $total;

            if ($line->system !== InvoiceLine::SYSTEM_STAMP_DUTY) {
                $base += $total;
            }

            $this->addToGroup($groups, (float) $line->vat_rate, $line->vat_nature, $total);
        }

        $pension = $invoice->apply_pension ? round($base * (float) $invoice->pension_rate / 100, 2) : 0.0;

        if ($pension != 0.0) {
            $this->addToGroup($groups, (float) $invoice->pension_vat_rate, $invoice->pension_vat_nature, $pension);
        }

        $summary = [];

        foreach ($groups as $group) {
            $group['taxable'] = round($group['taxable'], 2);
            $group['vat'] = round($group['taxable'] * $group['rate'] / 100, 2);
            $summary[] = $group;
        }

        usort($summary, fn (array $a, array $b) => $b['rate'] <=> $a['rate']);

        $subtotal = round($subtotal, 2);
        $vatTotal = round(array_sum(array_column($summary, 'vat')), 2);
        $total = round($subtotal + $pension + $vatTotal, 2);

        $withholdingBase = $base + ($invoice->pension_withholding ? $pension : 0);
        $withholding = $invoice->apply_withholding ? round($withholdingBase * (float) $invoice->withholding_rate / 100, 2) : 0.0;

        return [
            'subtotal' => $subtotal,
            'pension_base' => $invoice->apply_pension ? round($base, 2) : 0.0,
            'pension_amount' => $pension,
            'vat_total' => $vatTotal,
            'total' => $total,
            'withholding_amount' => $withholding,
            'amount_due' => round($total - $withholding - ($invoice->split_payment ? $vatTotal : 0), 2),
            'vat_summary' => $summary,
        ];
    }

    /**
     * Amount of the operations without VAT, used to decide the stamp duty.
     *
     * @param  Collection<int, InvoiceLine>|list<array<string, mixed>>  $lines
     */
    public function exemptAmount(Collection $lines, Invoice $invoice): float
    {
        $amount = 0.0;
        $base = 0.0;

        foreach ($lines as $line) {
            if (! $line->isItem() || $line->system === InvoiceLine::SYSTEM_STAMP_DUTY) {
                continue;
            }

            $total = DocumentTotals::lineTotal((float) $line->quantity, (float) $line->unit_price, (float) $line->discount_percent);
            $base += $total;

            if (in_array($line->vat_nature, self::STAMP_DUTY_NATURES, true)) {
                $amount += $total;
            }
        }

        if ($invoice->apply_pension && in_array($invoice->pension_vat_nature, self::STAMP_DUTY_NATURES, true)) {
            $amount += round($base * (float) $invoice->pension_rate / 100, 2);
        }

        return round($amount, 2);
    }

    public function stampDutyDue(float $exemptAmount): bool
    {
        return $exemptAmount > FiscalCodes::STAMP_DUTY_THRESHOLD;
    }

    /**
     * @param  array<string, array{rate: float, nature: string|null, taxable: float, vat: float}>  $groups
     */
    private function addToGroup(array &$groups, float $rate, ?string $nature, float $amount): void
    {
        $key = $rate.'|'.$nature;
        $groups[$key] ??= ['rate' => $rate, 'nature' => $nature, 'taxable' => 0.0, 'vat' => 0.0];
        $groups[$key]['taxable'] += $amount;
    }
}
