<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id', 'contact_id', 'owner_id', 'customer_id', 'order_id', 'title', 'order_date', 'expected_date',
    'supplier_reference', 'payment_term_id', 'notes', 'internal_notes',
])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'supplier' => 'array',
            'order_date' => 'date',
            'expected_date' => 'date',
            'sent_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('position');
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
     * Lines can change while nothing has been charged back to the customer.
     */
    public function isEditable(): bool
    {
        return $this->status !== PurchaseOrderStatus::Cancelled
            && ! $this->lines()->whereHas('rebilledBy')->exists();
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('supplier_reference', 'like', "%{$term}%")
                ->orWhereHas('supplierOrganization', fn (Builder $query) => $query->withTrashed()->where('name', 'like', "%{$term}%")),
        ));
    }
}
