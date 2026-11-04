<?php

namespace App\Models;

use App\Enums\InterventionMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kind of intervention ("Assistenza sistemistica", "Sviluppo"...), billed with one service
 * of the catalog for remote work and one for on-site work (so customer prices apply).
 */
#[Fillable(['name', 'remote_product_id', 'onsite_product_id', 'is_active', 'sort'])]
class InterventionCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function remoteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'remote_product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function onsiteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'onsite_product_id');
    }

    /**
     * @return HasMany<Intervention, $this>
     */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'category_id');
    }

    /**
     * Service billed for the hours in the given mode.
     */
    public function productFor(InterventionMode $mode): ?Product
    {
        return $mode === InterventionMode::Onsite ? $this->onsiteProduct : $this->remoteProduct;
    }

    /**
     * @param  Builder<InterventionCategory>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }
}
