<?php

namespace App\Models;

use App\Enums\QuoteLineType;
use App\Models\Concerns\IsDocumentLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
