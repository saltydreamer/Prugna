<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseType;
use App\Enums\PaymentMethod;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'category', 'supplier_id', 'number', 'document_date', 'title', 'customer_id', 'order_id', 'payment_method',
    'due_date', 'paid_at', 'notes', 'owner_id',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExpenseType::class,
            'category' => ExpenseCategory::class,
            'payment_method' => PaymentMethod::class,
            'supplier' => 'array',
            'document_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'date',
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ExpenseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function supplierOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_id')->withTrashed();
    }

    /**
     * Customer the rebill lines are charged to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<AiExtraction, $this>
     */
    public function aiExtraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date?->lt(today());
    }

    /**
     * Lines can change while nothing has been charged back to the customer.
     */
    public function isEditable(): bool
    {
        return ! $this->lines()->whereHas('rebilledBy')->exists();
    }

    /**
     * Still to pay (credit notes excluded).
     *
     * @param  Builder<Expense>  $query
     */
    public function scopeUnpaid(Builder $query): void
    {
        $query->whereNull('paid_at')->where('type', '!=', ExpenseType::CreditNote->value);
    }

    /**
     * @param  Builder<Expense>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->unpaid()->whereDate('due_date', '<', today());
    }

    /**
     * @param  Builder<Expense>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('supplier->name', 'like', "%{$term}%"),
        ));
    }
}
