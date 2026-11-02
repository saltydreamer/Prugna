<?php

namespace App\Models;

use App\Enums\InvoiceType;
use App\Enums\QuoteLineType;
use App\Models\Concerns\IsDocumentLine;
use App\Models\Concerns\IsRebillableCost;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'position', 'type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent',
    'vat_rate_id', 'vat_rate', 'vat_nature', 'total', 'rebill', 'markup_percent',
])]
class PurchaseOrderLine extends Model
{
    use IsDocumentLine, IsRebillableCost;

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
            'rebill' => 'boolean',
            'markup_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Invoice lines charging this cost back (credit notes excluded).
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function rebilledBy(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)
            ->whereHas('invoice', fn (Builder $query) => $query->where('type', '!=', InvoiceType::CreditNote->value));
    }

    /**
     * Rebill lines of committed (sent, confirmed, received) orders not yet in an invoice.
     *
     * @param  Builder<PurchaseOrderLine>  $query
     */
    public function scopeToRebill(Builder $query): void
    {
        $query->where('rebill', true)
            ->where('type', QuoteLineType::Item->value)
            ->whereDoesntHave('rebilledBy')
            ->whereHas('purchaseOrder', fn (Builder $query) => $query->whereIn('status', ['sent', 'confirmed', 'received']));
    }
}
