<?php

namespace App\Support\Dolibarr;

use App\Enums\QuoteLineType;
use App\Models\Product;
use App\Support\Dolibarr\DolibarrSource as Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Lines of Dolibarr documents (proposals, orders, invoices, supplier documents) as Prugna
 * document lines: product, description, unit, quantity, price, discount, VAT, total.
 */
class DocumentLines
{
    /** @var array<int, ?string> */
    private array $productUnits = [];

    public function __construct(private DolibarrMapper $map) {}

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  bool  $sections  Keep titles ("Subtotal" module) and free texts as section/text lines (quotes and orders).
     * @param  int  $sign  -1 for credit notes: Dolibarr stores them with negative amounts.
     * @return list<array<string, mixed>>
     */
    public function build(Collection $rows, string $reference, string $priceColumn = 'subprice', bool $sections = false, int $sign = 1, ?string $fallbackNature = null): array
    {
        $lines = [];

        foreach ($rows->sortBy([fn ($a, $b) => (int) ($a->rang ?? 0) <=> (int) ($b->rang ?? 0) ?: (int) $a->rowid <=> (int) $b->rowid]) as $row) {
            $productType = (int) ($row->product_type ?? 0);
            $quantity = (float) ($row->qty ?? 0);
            $price = (float) ($row->{$priceColumn} ?? 0);
            $productId = $this->map->linkedId('product', Source::int($row->fk_product ?? null));
            $product = $this->map->source->dictionary('product', Source::int($row->fk_product ?? null));
            $label = Source::line($row->label ?? null) ?? ($product ? Source::line($product->label) : null);
            $description = Source::text($row->description ?? null);
            $text = $label;

            if ($description !== null && $description !== $label) {
                $text = $text !== null ? "{$text}\n{$description}" : $description;
            }

            // Titles (quantity 1-9) and subtotals (90-99) of the "Subtotal" module.
            if ($productType === 9) {
                if ($sections && $quantity < 10 && $text !== null) {
                    $lines[] = $this->textLine(QuoteLineType::Section, $text, count($lines) + 1);
                }

                continue;
            }

            if ($sections && ! $productId && $quantity == 0 && $price == 0 && $text !== null) {
                $lines[] = $this->textLine(QuoteLineType::Text, $text, count($lines) + 1);

                continue;
            }

            $rate = round((float) ($row->tva_tx ?? 0), 2);
            $nature = $this->map->nature($rate, $row->vat_src_code ?? null, $text, $fallbackNature);

            if ($rate == 0 && $nature === null) {
                $this->map->report->warn(__('Righe con IVA 0% senza natura (N1–N7): indicala prima di rigenerare l\'XML del documento.'), $reference);
            }

            $lines[] = [
                'position' => count($lines) + 1,
                'type' => QuoteLineType::Item,
                'product_id' => $productId,
                'code' => Source::line($product->ref ?? ($row->ref ?? null), 64),
                'description' => $text ?? '—',
                'unit' => $this->map->unit($row->fk_unit ?? null)?->value ?? $this->productUnit($productId),
                'quantity' => round($quantity, 3),
                'unit_price' => Source::amount($sign * $price, 4),
                'discount_percent' => Source::amount(min(100, max(0, (float) ($row->remise_percent ?? 0)))),
                'vat_rate_id' => $this->map->vatRate($rate, $nature),
                'vat_rate' => Source::amount($rate),
                'vat_nature' => $nature,
                'total' => Source::amount($sign * (float) ($row->total_ht ?? 0)),
            ];
        }

        return $lines;
    }

    /**
     * Creates the lines of a document (forced: imported values are not mass assignable).
     *
     * @param  HasMany<covariant Model, covariant Model>  $relation
     * @param  list<array<string, mixed>>  $lines
     */
    public function save(HasMany $relation, array $lines): void
    {
        foreach ($lines as $line) {
            $relation->forceCreate($line);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textLine(QuoteLineType $type, string $text, int $position): array
    {
        return [
            'position' => $position,
            'type' => $type,
            'product_id' => null,
            'code' => null,
            'description' => $text,
            'unit' => null,
            'quantity' => 0,
            'unit_price' => 0,
            'discount_percent' => 0,
            'vat_rate_id' => null,
            'vat_rate' => 0,
            'vat_nature' => null,
            'total' => 0,
        ];
    }

    private function productUnit(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        if (! array_key_exists($productId, $this->productUnits)) {
            $this->productUnits[$productId] = Product::query()->whereKey($productId)->first()?->unit?->value;
        }

        return $this->productUnits[$productId];
    }
}
