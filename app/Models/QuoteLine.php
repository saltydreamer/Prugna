<?php

namespace App\Models;

use App\Enums\QuoteLineType;
use App\Models\Concerns\IsDocumentLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'position', 'type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent',
    'vat_rate_id', 'vat_rate', 'vat_nature', 'is_optional', 'is_selected', 'total', 'package_hours', 'package_months',
])]
class QuoteLine extends Model
{
    use IsDocumentLine;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuoteLineType::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'is_optional' => 'boolean',
            'is_selected' => 'boolean',
            'total' => 'decimal:2',
            'package_hours' => 'decimal:2',
            'package_months' => 'integer',
        ];
    }

    /**
     * Hour package: becomes an hour contract of the customer when the quote is accepted.
     */
    public function isHourPackage(): bool
    {
        return $this->isItem() && $this->package_hours !== null && (float) $this->package_hours > 0;
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
