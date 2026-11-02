<?php

namespace App\Models;

use App\Enums\QuoteLineType;
use App\Models\Concerns\IsDocumentLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'position', 'type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent',
    'vat_rate_id', 'vat_rate', 'vat_nature', 'total', 'quantity_invoiced',
])]
class OrderLine extends Model
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
            'quantity_invoiced' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->quantity_invoiced, 3));
    }
}
