<?php

namespace App\Models;

use App\Enums\QuoteLineType;
use App\Models\Concerns\IsDocumentLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'position', 'type', 'product_id', 'order_line_id', 'system', 'deducts_invoice_id', 'code', 'description', 'unit',
    'quantity', 'unit_price', 'discount_percent', 'vat_rate_id', 'vat_rate', 'vat_nature', 'total',
])]
class InvoiceLine extends Model
{
    use IsDocumentLine;

    public const SYSTEM_STAMP_DUTY = 'stamp_duty';

    public const SYSTEM_DEPOSIT_DEDUCTION = 'deposit_deduction';

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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Costs charged back by this line (one or more, e.g. a single "spese" line).
     *
     * @return HasMany<InvoiceLineCost, $this>
     */
    public function costLinks(): HasMany
    {
        return $this->hasMany(InvoiceLineCost::class);
    }

    /**
     * Interventions billed by this line (hours, call-out, km, travel or materials).
     *
     * @return BelongsToMany<Intervention, $this>
     */
    public function interventions(): BelongsToMany
    {
        return $this->belongsToMany(Intervention::class, 'invoice_line_interventions')->withTimestamps();
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
