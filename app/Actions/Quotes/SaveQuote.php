<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteLineType;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Quote;
use App\Models\VatRate;
use App\Support\CustomerSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a quote with its lines, freezing the customer data on the document
 * and assigning the next number (PRV-00001...) to new quotes.
 */
class SaveQuote
{
    public const NUMBER_PREFIX = 'PRV-';

    /**
     * @param  array<string, mixed>  $data  Validated header fields plus "lines".
     */
    public function handle(Quote $quote, array $data): Quote
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        return DB::transaction(function () use ($quote, $data, $lines): Quote {
            $quote->fill($data);
            $quote->customer = CustomerSnapshot::from(Organization::withTrashed()->findOrFail($quote->organization_id));
            $quote->payment_terms = $quote->payment_term_id ? PaymentTerm::find($quote->payment_term_id)?->name : null;

            if (! $quote->exists) {
                $this->assignNumber($quote);
            }

            $quote->save();
            $this->syncLines($quote, $lines);
            $this->refreshTotals($quote);

            return $quote;
        });
    }

    /**
     * Recomputes line and document totals (e.g. after the customer picks optional lines).
     */
    public function refreshTotals(Quote $quote): void
    {
        $quote->load('lines');

        foreach ($quote->lines as $line) {
            $line->total = $line->isItem()
                ? QuoteTotals::lineTotal((float) $line->quantity, (float) $line->unit_price, (float) $line->discount_percent)
                : 0;
            $line->saveQuietly();
        }

        $totals = QuoteTotals::summarize($quote->lines);

        $quote->forceFill([
            'subtotal' => $totals['subtotal'],
            'vat_total' => $totals['vat_total'],
            'total' => $totals['total'],
        ])->save();
    }

    /**
     * New copy of a quote as draft, with a new number and today's dates.
     */
    public function duplicate(Quote $source, int $ownerId, int $validityDays): Quote
    {
        $data = [
            ...$source->only(['organization_id', 'contact_id', 'title', 'payment_term_id', 'intro', 'terms', 'internal_notes']),
            'owner_id' => $ownerId,
            'issue_date' => today(),
            'valid_until' => today()->addDays($validityDays),
            'lines' => $source->lines->map(fn ($line) => $line->only([
                'type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent',
                'vat_rate_id', 'is_optional', 'is_selected',
            ]))->all(),
        ];

        return $this->handle(new Quote, $data);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(Quote $quote, array $lines): void
    {
        $quote->lines()->delete();

        $rates = VatRate::query()->whereIn('id', array_filter(array_column($lines, 'vat_rate_id')))->get()->keyBy('id');

        foreach (array_values($lines) as $position => $line) {
            $type = $line['type'] ?? QuoteLineType::Item;
            $type = $type instanceof QuoteLineType ? $type : QuoteLineType::from($type);
            $isItem = $type === QuoteLineType::Item;
            $rate = $isItem ? $rates->get($line['vat_rate_id'] ?? null) : null;

            $quote->lines()->create([
                'position' => $position + 1,
                'type' => $type,
                'product_id' => $isItem ? ($line['product_id'] ?? null) : null,
                'code' => $isItem ? ($line['code'] ?? null) : null,
                'description' => $line['description'],
                'unit' => $isItem ? ($line['unit'] ?? null) : null,
                'quantity' => $isItem ? $line['quantity'] : 0,
                'unit_price' => $isItem ? $line['unit_price'] : 0,
                'discount_percent' => $isItem ? ($line['discount_percent'] ?? 0) : 0,
                'vat_rate_id' => $rate?->id,
                'vat_rate' => $rate?->rate ?? 0,
                'vat_nature' => $rate?->nature,
                'is_optional' => $isItem && ($line['is_optional'] ?? false),
                'is_selected' => ! ($isItem && ($line['is_optional'] ?? false)) || ($line['is_selected'] ?? false),
            ]);
        }
    }

    /**
     * Next sequence number; the unique index guards against concurrent creations.
     */
    private function assignNumber(Quote $quote): void
    {
        $sequence = (int) Quote::query()->lockForUpdate()->max('sequence') + 1;

        $quote->sequence = $sequence;
        $quote->number = self::NUMBER_PREFIX.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
