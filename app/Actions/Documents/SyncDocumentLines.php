<?php

namespace App\Actions\Documents;

use App\Enums\QuoteLineType;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Replaces the lines of an order or invoice from validated arrays, freezing the VAT rate
 * and computing each line total.
 */
class SyncDocumentLines
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $extra  Additional attributes copied as they are (e.g. order_line_id).
     * @return list<Model> The created lines, in the same order as $lines.
     */
    public function handle(HasMany $relation, array $lines, array $extra = []): array
    {
        $created = [];

        $relation->delete();

        $rates = VatRate::query()->whereIn('id', array_filter(array_column($lines, 'vat_rate_id')))->get()->keyBy('id');

        foreach (array_values($lines) as $position => $line) {
            $type = $line['type'] ?? QuoteLineType::Item;
            $type = $type instanceof QuoteLineType ? $type : QuoteLineType::from($type);
            $isItem = $type === QuoteLineType::Item;
            $rate = $isItem ? $rates->get($line['vat_rate_id'] ?? null) : null;

            $quantity = $isItem ? (float) $line['quantity'] : 0;
            $unitPrice = $isItem ? (float) $line['unit_price'] : 0;
            $discount = $isItem ? (float) ($line['discount_percent'] ?? 0) : 0;

            $created[] = $relation->create([
                ...array_intersect_key($line, array_flip($extra)),
                'position' => $position + 1,
                'type' => $type,
                'product_id' => $isItem ? ($line['product_id'] ?? null) : null,
                'code' => $isItem ? ($line['code'] ?? null) : null,
                'description' => $line['description'],
                'unit' => $isItem ? ($line['unit'] ?? null) : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_percent' => $discount,
                'vat_rate_id' => $rate?->id,
                'vat_rate' => $rate?->rate ?? 0,
                'vat_nature' => $rate?->nature,
                'total' => $isItem ? DocumentTotals::lineTotal($quantity, $unitPrice, $discount) : 0,
            ]);
        }

        return $created;
    }
}
