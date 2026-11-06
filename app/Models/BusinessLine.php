<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Line of business (e.g. Assistenza, Hosting, Sviluppo): revenue through the products of the
 * invoice lines, costs through the expenses and the movements.
 */
#[Fillable(['name', 'is_active', 'sort'])]
class BusinessLine extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @param  Builder<BusinessLine>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    /**
     * @return list<array{value: int, title: string}>
     */
    public static function options(?int $currentId = null): array
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($currentId, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->ordered()
            ->get()
            ->map(fn (self $line) => ['value' => $line->id, 'title' => $line->name])
            ->all();
    }
}
