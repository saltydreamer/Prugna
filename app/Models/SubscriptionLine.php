<?php

namespace App\Models;

use App\Enums\QuoteLineType;
use App\Models\Concerns\IsDocumentLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Service of a contract, charged every period at the agreed price.
 */
#[Fillable([
    'position', 'type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent',
    'vat_rate_id', 'vat_rate', 'vat_nature', 'total',
])]
class SubscriptionLine extends Model
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
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
