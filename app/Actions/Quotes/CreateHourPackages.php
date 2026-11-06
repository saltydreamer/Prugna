<?php

namespace App\Actions\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\ServiceContract;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * When a quote is accepted, its hour packages (selected lines with package_hours) become hour
 * contracts of the customer: the interventions use them up (ApplyContract). The package is
 * invoiced with the quote or its order, so the contract is never invoiced on its own. Once per line.
 */
class CreateHourPackages
{
    /**
     * @return list<ServiceContract> The contracts created now.
     */
    public function handle(Quote $quote): array
    {
        $lines = $quote->lines()->get()->filter(fn (QuoteLine $line) => $line->isHourPackage() && $line->isIncluded());

        if ($lines->isEmpty()) {
            return [];
        }

        $existing = ServiceContract::query()->whereIn('quote_line_id', $lines->pluck('id'))->pluck('quote_line_id')->all();
        $start = now(config('app.display_timezone'))->startOfDay();

        return DB::transaction(fn () => $lines
            ->reject(fn (QuoteLine $line) => in_array($line->id, $existing, true))
            ->map(function (QuoteLine $line) use ($quote, $start): ServiceContract {
                $contract = new ServiceContract([
                    'organization_id' => $quote->organization_id,
                    'title' => Str::limit(trim(strtok($line->description, "\n")) ?: __('Pacchetto ore'), 250),
                    'hours' => $line->package_hours,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $line->package_months ? $start->copy()->addMonthsNoOverflow($line->package_months)->subDay()->toDateString() : null,
                    'mode' => null,
                    'category_ids' => [],
                    'price' => round((float) $line->total, 2),
                    'product_id' => $line->product_id,
                    'vat_rate_id' => $line->vat_rate_id,
                    'manager_id' => $quote->owner_id,
                    'is_active' => true,
                    'notes' => __('Dal preventivo :number: si fattura con il preventivo o con il suo ordine.', ['number' => $quote->number]),
                ]);

                $contract->forceFill([
                    ...DocumentNumber::next(ServiceContract::class, 'MO', (int) $start->format('Y')),
                    'quote_id' => $quote->id,
                    'quote_line_id' => $line->id,
                ])->save();

                return $contract;
            })
            ->values()
            ->all());
    }
}
