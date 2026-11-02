<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\QuoteLineType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'quote_id', 'organization_id', 'contact_id', 'owner_id', 'customer', 'title', 'order_date', 'delivery_date',
    'customer_reference', 'customer_reference_date', 'payment_term_id', 'payment_terms', 'notes', 'internal_notes',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'customer' => 'array',
            'order_date' => 'date',
            'delivery_date' => 'date',
            'customer_reference_date' => 'date',
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Supplier orders placed for this order.
     *
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Lines and header can change only while nothing has been invoiced.
     */
    public function isEditable(): bool
    {
        return ! in_array($this->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
            && ! $this->invoices()->exists();
    }

    /**
     * Recomputes the invoiced quantity of each line from invoices (drafts included) and credit notes.
     */
    public function refreshInvoicedQuantities(): void
    {
        foreach ($this->lines()->get() as $line) {
            $invoiced = InvoiceLine::query()
                ->where('order_line_id', $line->id)
                ->whereHas('invoice', fn (Builder $query) => $query->where('type', InvoiceType::Invoice->value))
                ->sum('quantity');

            $credited = InvoiceLine::query()
                ->where('order_line_id', $line->id)
                ->whereHas('invoice', fn (Builder $query) => $query->where('type', InvoiceType::CreditNote->value)->where('status', InvoiceStatus::Issued->value))
                ->sum('quantity');

            $line->forceFill(['quantity_invoiced' => max(0, round((float) $invoiced - (float) $credited, 3))])->saveQuietly();
        }
    }

    /**
     * Invoiced vs still to invoice (VAT excluded), net of deposits not yet deducted.
     *
     * @return array{invoiced: float, remaining: float, deposits_open: float, percent: float}
     */
    public function billingSummary(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        $total = 0.0;
        $remaining = 0.0;

        foreach ($lines as $line) {
            if (! $line->isItem()) {
                continue;
            }

            $unit = (float) $line->unit_price * (1 - (float) $line->discount_percent / 100);
            $total += (float) $line->total;
            $remaining += round($line->remainingQuantity() * $unit, 2);
        }

        $depositsOpen = (float) $this->openDeposits()->sum('subtotal');
        $invoiced = round($total - $remaining, 2);

        return [
            'invoiced' => $invoiced,
            'remaining' => round($remaining, 2),
            'deposits_open' => round($depositsOpen, 2),
            'percent' => $total > 0 ? round($invoiced / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Issued deposit invoices of this order not yet deducted by a balance invoice.
     *
     * @return HasMany<Invoice, $this>
     */
    public function openDeposits(): HasMany
    {
        return $this->invoices()
            ->where('type', InvoiceType::Deposit->value)
            ->where('status', InvoiceStatus::Issued->value)
            ->whereDoesntHave('deductedBy');
    }

    /**
     * Adds "invoiced_amount": taxable amount of the invoiced quantities.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeWithInvoicedAmount(Builder $query): void
    {
        $query->select('orders.*')->selectSub(
            OrderLine::query()
                ->selectRaw('coalesce(sum(quantity_invoiced * unit_price * (1 - discount_percent / 100)), 0)')
                ->whereColumn('order_lines.order_id', 'orders.id')
                ->where('type', QuoteLineType::Item->value),
            'invoiced_amount',
        );
    }

    /**
     * Not cancelled orders with quantities still to invoice.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeToInvoice(Builder $query): void
    {
        $query->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereHas('lines', fn (Builder $query) => $query
                ->where('type', QuoteLineType::Item->value)
                ->whereColumn('quantity_invoiced', '<', 'quantity'));
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('customer_reference', 'like', "%{$term}%")
                ->orWhereHas('organization', fn (Builder $query) => $query->withTrashed()->where('name', 'like', "%{$term}%")),
        ));
    }
}
