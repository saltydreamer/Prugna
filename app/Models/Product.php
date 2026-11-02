<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\ProductType;
use App\Enums\UnitOfMeasure;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'code', 'name', 'description', 'category_id', 'unit', 'price', 'cost', 'vat_rate_id', 'billing_period', 'is_active', 'notes'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'unit' => UnitOfMeasure::class,
            'billing_period' => BillingPeriod::class,
            'price' => 'decimal:4',
            'cost' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * @return BelongsTo<VatRate, $this>
     */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    /**
     * @return HasMany<CustomerPrice, $this>
     */
    public function customerPrices(): HasMany
    {
        return $this->hasMany(CustomerPrice::class);
    }

    public function isRecurring(): bool
    {
        return $this->billing_period !== null;
    }

    /**
     * Margin on the selling price, in euro and percent; null when the cost is unknown.
     *
     * @return array{amount: float, percent: float|null}|null
     */
    public function margin(): ?array
    {
        if ($this->cost === null) {
            return null;
        }

        $price = (float) $this->price;
        $amount = round($price - (float) $this->cost, 4);

        return [
            'amount' => $amount,
            'percent' => $price > 0 ? round($amount / $price * 100, 1) : null,
        ];
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%"),
        ));
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Next free code with the given prefix: P00001, S00001…
     */
    public static function nextCode(ProductType $type): string
    {
        $prefix = $type === ProductType::Service ? 'S' : 'P';

        $last = static::query()
            ->where('code', 'like', "{$prefix}%")
            ->pluck('code')
            ->map(fn (string $code) => preg_match('/^'.$prefix.'(\d+)$/', $code, $matches) ? (int) $matches[1] : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
