<?php

namespace App\Support\Accounting;

use App\Support\Settings;

/**
 * Estimate of what the flat-rate regime (forfettario, RF19) costs for a year, to set money aside:
 * taxable income = collected × profitability coefficient − contributions paid in the year;
 * imposta sostitutiva at 15% (5% for the first five years of a new business);
 * INPS contributions on the gross income (gestione separata), or the fixed amount on the minimum
 * income plus a percentage above it (artigiani / commercianti, optionally reduced by 35%).
 * Only an estimate: the parameters change every year and the tax return decides.
 */
class FlatRateTaxEstimate
{
    public const REVENUE_LIMIT = 85000;

    public const INPS_SEPARATE = 'separata';

    public const INPS_CRAFTS = 'artigiani_commercianti';

    public const INPS_NONE = 'none';

    public function __construct(private Settings $settings) {}

    public function applies(): bool
    {
        return $this->settings->get('fiscal.regime') === 'RF19';
    }

    /**
     * @return array{
     *     coefficient: float, tax_rate: float, inps_regime: string, inps_rate: float, inps_minimum_income: float, inps_fixed: float, inps_reduction: bool
     * }
     */
    public function parameters(): array
    {
        return [
            'coefficient' => (float) $this->settings->get('accounting.coefficient'),
            'tax_rate' => (float) $this->settings->get('accounting.tax_rate'),
            'inps_regime' => (string) $this->settings->get('accounting.inps_regime'),
            'inps_rate' => (float) $this->settings->get('accounting.inps_rate'),
            'inps_minimum_income' => (float) $this->settings->get('accounting.inps_minimum_income'),
            'inps_fixed' => (float) $this->settings->get('accounting.inps_fixed'),
            'inps_reduction' => (bool) $this->settings->get('accounting.inps_reduction'),
        ];
    }

    /**
     * @param  array{collected: float, contributions: float, taxes: float}  $profitAndLoss  ProfitAndLoss::forYear()
     * @return array{
     *     collected: float, gross_income: float, contributions_due: float, contributions_paid: float,
     *     taxable: float, tax: float, set_aside: float, set_aside_percent: float, taxes_paid: float, over_limit: bool
     * }
     */
    public function estimate(array $profitAndLoss): array
    {
        $parameters = $this->parameters();
        $collected = max(0.0, (float) $profitAndLoss['collected']);
        $grossIncome = $collected * $parameters['coefficient'] / 100;

        $contributionsDue = match ($parameters['inps_regime']) {
            self::INPS_SEPARATE => $grossIncome * $parameters['inps_rate'] / 100,
            self::INPS_CRAFTS => ($parameters['inps_fixed'] + max(0.0, $grossIncome - $parameters['inps_minimum_income']) * $parameters['inps_rate'] / 100)
                * ($parameters['inps_reduction'] ? 0.65 : 1),
            default => 0.0,
        };

        // Contributions paid in the year are deducted from the income.
        $contributionsPaid = max(0.0, (float) $profitAndLoss['contributions']);
        $taxable = max(0.0, $grossIncome - $contributionsPaid);
        $tax = $taxable * $parameters['tax_rate'] / 100;
        $setAside = $tax + $contributionsDue;

        return [
            'collected' => round($collected, 2),
            'gross_income' => round($grossIncome, 2),
            'contributions_due' => round($contributionsDue, 2),
            'contributions_paid' => round($contributionsPaid, 2),
            'taxable' => round($taxable, 2),
            'tax' => round($tax, 2),
            'set_aside' => round($setAside, 2),
            'set_aside_percent' => $collected > 0 ? round($setAside / $collected * 100, 1) : 0.0,
            'taxes_paid' => round(max(0.0, (float) $profitAndLoss['taxes']), 2),
            'over_limit' => $collected > self::REVENUE_LIMIT,
        ];
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function inpsOptions(): array
    {
        return [
            ['value' => self::INPS_SEPARATE, 'title' => 'Gestione separata INPS (percentuale sul reddito)'],
            ['value' => self::INPS_CRAFTS, 'title' => 'Artigiani / commercianti (fissi sul minimale + percentuale oltre)'],
            ['value' => self::INPS_NONE, 'title' => 'Nessuno (cassa professionale o già calcolati a parte)'],
        ];
    }
}
