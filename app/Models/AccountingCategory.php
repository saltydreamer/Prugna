<?php

namespace App\Models;

use App\Enums\AccountingCategoryKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category of the accounts, chosen by the user: costs (expenses and payments without a document),
 * other revenue, taxes and social security contributions (kept apart in the profit and loss).
 */
#[Fillable(['name', 'kind', 'business_share', 'is_active', 'sort'])]
class AccountingCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AccountingCategoryKind::class,
            'business_share' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    /**
     * @return HasMany<AccountMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(AccountMovement::class, 'category_id');
    }

    public function isUsed(): bool
    {
        return $this->expenses()->exists() || $this->movements()->exists();
    }

    /**
     * @param  Builder<AccountingCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    /**
     * Options for the pickers: active categories plus the current one.
     *
     * @return list<array{value: int, title: string, kind: string, business_share: float}>
     */
    public static function options(?int $currentId = null): array
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($currentId, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->ordered()
            ->get()
            ->map(fn (self $category) => [
                'value' => $category->id,
                'title' => $category->name,
                'kind' => $category->kind->value,
                'business_share' => (float) $category->business_share,
            ])
            ->all();
    }
}
