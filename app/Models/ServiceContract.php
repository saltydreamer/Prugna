<?php

namespace App\Models;

use App\Enums\InterventionMode;
use Carbon\CarbonInterface;
use Database\Factories\ServiceContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contratto a monte ore: a customer buys a number of hours (invoiced when activated); the covered
 * interventions (mode, categories, period) use them up instead of being invoiced.
 */
#[Fillable([
    'organization_id', 'title', 'hours', 'starts_on', 'ends_on', 'mode', 'category_ids', 'price', 'product_id', 'vat_rate_id',
    'manager_id', 'is_active', 'notes',
])]
class ServiceContract extends Model
{
    /** @use HasFactory<ServiceContractFactory> */
    use HasFactory;

    /** Share of the hours used that triggers the first alert. */
    public const ALERT_THRESHOLD = 0.8;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'mode' => InterventionMode::class,
            'category_ids' => 'array',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'alerted_threshold_at' => 'datetime',
            'alerted_exhausted_at' => 'datetime',
        ];
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
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Invoice of the package (set when invoiced).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Quote that sold the package (invoiced with the quote or its order).
     *
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Invoiced with its own invoice or with the documents of the quote that sold it.
     */
    public function isBilled(): bool
    {
        return $this->invoice_id !== null || $this->quote_id !== null;
    }

    /**
     * @return HasMany<Intervention, $this>
     */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'contract_id');
    }

    public function totalMinutes(): int
    {
        return (int) round((float) $this->hours * 60);
    }

    public function usedMinutes(?int $exceptInterventionId = null): int
    {
        return (int) $this->interventions()->when($exceptInterventionId, fn (Builder $query, int $id) => $query->whereKeyNot($id))->sum('contract_minutes');
    }

    public function remainingMinutes(?int $exceptInterventionId = null): int
    {
        return max(0, $this->totalMinutes() - $this->usedMinutes($exceptInterventionId));
    }

    /**
     * Dates are compared as calendar days ("2026-11-04"): the date columns carry no timezone.
     */
    public function isExpiredOn(CarbonInterface $date): bool
    {
        return $this->ends_on !== null && $this->ends_on->toDateString() < $date->toDateString();
    }

    public function startsAfter(CarbonInterface $date): bool
    {
        return $this->starts_on->toDateString() > $date->toDateString();
    }

    /**
     * active / upcoming / expired / exhausted / closed.
     */
    public function status(): string
    {
        $today = now(config('app.display_timezone'));

        return match (true) {
            ! $this->is_active => 'closed',
            $this->startsAfter($today) => 'upcoming',
            $this->isExpiredOn($today) => 'expired',
            $this->remainingMinutes() <= 0 => 'exhausted',
            default => 'active',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('Attivo'),
            'upcoming' => __('Non ancora iniziato'),
            'expired' => __('Scaduto'),
            'exhausted' => __('Ore esaurite'),
            default => __('Chiuso'),
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'upcoming' => 'info',
            'exhausted' => 'error',
            'expired' => 'warning',
            default => 'grey',
        };
    }

    /**
     * Whether the contract covers an intervention of this mode and category on this (local) date.
     */
    public function covers(InterventionMode $mode, int $categoryId, CarbonInterface $date): bool
    {
        return $this->is_active
            && ! $this->startsAfter($date)
            && ! $this->isExpiredOn($date)
            && ($this->mode === null || $this->mode === $mode)
            && (empty($this->category_ids) || in_array($categoryId, array_map('intval', $this->category_ids), true));
    }

    /**
     * Contracts of a customer that may cover interventions on a date.
     *
     * @param  Builder<ServiceContract>  $query
     */
    public function scopeUsableFor(Builder $query, int $organizationId, CarbonInterface $date): void
    {
        $query->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date->toDateString()));
    }

    /**
     * @param  Builder<ServiceContract>  $query
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
