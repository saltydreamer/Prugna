<?php

namespace App\Actions\Invoices;

use App\Models\VatRate;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Settings;

/**
 * VAT rate for a new invoice line given the fiscal regime: in the flat-rate regime every line is "non soggetta" (N2.2).
 */
class RegimeVatRate
{
    public function __construct(private Settings $settings) {}

    public function for(?int $vatRateId): ?int
    {
        if (! in_array($this->settings->get('fiscal.regime'), FiscalCodes::VAT_FREE_REGIMES, true)) {
            return $vatRateId;
        }

        return VatRate::query()->where('nature', 'N2.2')->value('id') ?? $vatRateId;
    }
}
