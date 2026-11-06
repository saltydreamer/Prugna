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
    'vat_rate_id', 'vat_rate', 'vat_nature', 'total', 'quantity_invoiced', 'package_hours', 'package_months',
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
            'package_hours' => 'decimal:2',
            'package_months' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Hours of the package the line sells: set on the line, or the quantity of an article sold as
     * a "monte ore prepagato" (orders saved before the line carried them, imported ones).
     */
    public function packageHours(): ?float
    {
        if (! $this->isItem()) {
            return null;
        }

        $hours = $this->package_hours !== null ? (float) $this->package_hours : ($this->product?->hour_package ? (float) $this->quantity : null);

        return $hours !== null && $hours > 0 ? $hours : null;
    }

    public function packageMonths(): ?int
    {
        return $this->package_hours !== null ? $this->package_months : $this->product?->package_months;
    }

    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->quantity_invoiced, 3));
    }
}
