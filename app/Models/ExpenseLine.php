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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'position', 'type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent',
    'vat_rate_id', 'vat_rate', 'vat_nature', 'total', 'rebill', 'markup_percent',
])]
class ExpenseLine extends Model
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
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Invoice lines charging this cost back (credit notes excluded).
     *
     * @return BelongsToMany<InvoiceLine, $this>
     */
    public function rebilledBy(): BelongsToMany
    {
        return $this->belongsToMany(InvoiceLine::class, 'invoice_line_costs', 'expense_line_id', 'invoice_line_id')
            ->whereHas('invoice', fn (Builder $query) => $query->where('type', '!=', InvoiceType::CreditNote->value));
    }

    /**
     * Cost lines that can still be charged back: not yet in an invoice
     * (supplier credit notes never are).
     *
     * @param  Builder<ExpenseLine>  $query
     */
    public function scopeNotRebilled(Builder $query): void
    {
        $query->where('type', QuoteLineType::Item->value)
            ->whereDoesntHave('rebilledBy')
            ->whereHas('expense', fn (Builder $query) => $query->where('type', '!=', 'credit_note'));
    }

    /**
     * Lines marked "rebill" not yet in an invoice.
     *
     * @param  Builder<ExpenseLine>  $query
     */
    public function scopeToRebill(Builder $query): void
    {
        $query->notRebilled()->where('rebill', true);
    }
}
