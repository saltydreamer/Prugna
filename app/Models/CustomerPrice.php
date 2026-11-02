<?php

namespace App\Models;

use Database\Factories\CustomerPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Price agreed with a customer: a fixed price or a discount on the list price.
 */
#[Fillable(['organization_id', 'product_id', 'price', 'discount_percent', 'valid_until', 'notes'])]
class CustomerPrice extends Model
{
    /** @use HasFactory<CustomerPriceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'valid_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isExpired(?Carbon $on = null): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(($on ?? now())->startOfDay());
    }

    /**
     * @param  Builder<CustomerPrice>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
    }
}
