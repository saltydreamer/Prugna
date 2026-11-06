<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Preventivatore (experimental): a solution priced at cost, sold as a monthly fee over a fixed term.
 * Accepted, it becomes a service contract (Subscription) of the customer.
 */
#[Fillable(['organization_id', 'title', 'intro', 'months', 'markup_percent', 'category_markups', 'upfront', 'starts_on', 'advantages', 'notes', 'owner_id'])]
class Estimate extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACCEPTED = 'accepted';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'category_markups' => 'array',
            'upfront' => 'decimal:2',
            'starts_on' => 'date',
            'months' => 'integer',
        ];
    }

    /**
     * @return HasMany<EstimateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * Markup of a category (the general one unless set for that category).
     */
    public function markupFor(?string $category): float
    {
        $markups = $this->category_markups ?? [];

        return (float) ($category !== null && isset($markups[$category]) ? $markups[$category] : $this->markup_percent);
    }

    /**
     * @param  Builder<Estimate>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhereHas('organization', fn (Builder $query) => $query->withTrashed()->where('name', 'like', "%{$term}%")),
        ));
    }
}
