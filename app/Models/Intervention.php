<?php

namespace App\Models;

use App\Enums\InterventionMode;
use App\Enums\InterventionStatus;
use App\Enums\InvoiceType;
use App\Enums\Permission;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Database\Factories\InterventionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Intervento: remote or on-site work for a customer. While the timer runs the duration is null.
 * Hours and travel are billed rounded up to the block set in Impostazioni interventi.
 * Once in an invoice (credit notes excluded) it can no longer change.
 *
 * @property CarbonInterface $started_at
 */
#[Fillable([
    'organization_id', 'location_id', 'contact_id', 'order_id', 'category_id', 'mode', 'technician_id', 'started_at',
    'duration_minutes', 'description', 'internal_notes', 'billable', 'callout', 'km', 'travel_minutes',
])]
class Intervention extends Model
{
    /** @use HasFactory<InterventionFactory> */
    use HasFactory;

    public const DEFAULT_ROUNDING = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => InterventionMode::class,
            'started_at' => 'datetime',
            'duration_minutes' => 'integer',
            'travel_minutes' => 'integer',
            'billable' => 'boolean',
            'callout' => 'boolean',
            'km' => 'decimal:1',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<InterventionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InterventionCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return HasMany<InterventionMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(InterventionMaterial::class)->orderBy('position');
    }

    /**
     * Invoice lines billing this intervention (credit notes excluded).
     *
     * @return BelongsToMany<InvoiceLine, $this>
     */
    public function invoiceLines(): BelongsToMany
    {
        return $this->belongsToMany(InvoiceLine::class, 'invoice_line_interventions')
            ->whereHas('invoice', fn (Builder $query) => $query->where('type', '!=', InvoiceType::CreditNote->value));
    }

    public function isRunning(): bool
    {
        return $this->duration_minutes === null;
    }

    public function isInvoiced(): bool
    {
        return $this->relationLoaded('invoiceLines') ? $this->invoiceLines->isNotEmpty() : $this->invoiceLines()->exists();
    }

    /**
     * Interventions in an invoice never change.
     */
    public function isEditable(): bool
    {
        return ! $this->isInvoiced();
    }

    public function status(): InterventionStatus
    {
        return match (true) {
            $this->isRunning() => InterventionStatus::Running,
            $this->isInvoiced() => InterventionStatus::Invoiced,
            ! $this->billable => InterventionStatus::NotBillable,
            default => InterventionStatus::ToInvoice,
        };
    }

    /**
     * Start in the local timezone, for documents and descriptions.
     */
    public function localStart(): CarbonInterface
    {
        return $this->started_at->copy()->setTimezone(config('app.display_timezone'));
    }

    public function endsAt(): ?CarbonInterface
    {
        return $this->isRunning() ? null : $this->started_at->copy()->addMinutes($this->duration_minutes);
    }

    public function isOnsite(): bool
    {
        return $this->mode === InterventionMode::Onsite;
    }

    /**
     * Minutes billed: the duration rounded up to the configured block.
     */
    public function billableMinutes(): int
    {
        return self::roundUp((int) $this->duration_minutes);
    }

    /**
     * Travel minutes billed (on site only), rounded like the work.
     */
    public function billableTravelMinutes(): int
    {
        return $this->isOnsite() ? self::roundUp($this->travel_minutes) : 0;
    }

    public static function rounding(): int
    {
        return max(1, (int) app(Settings::class)->get('interventions.rounding_minutes', self::DEFAULT_ROUNDING));
    }

    public static function roundUp(int $minutes, ?int $block = null): int
    {
        $block ??= self::rounding();

        return $minutes <= 0 ? 0 : (int) (ceil($minutes / $block) * $block);
    }

    /**
     * Counters for the order and customer pages.
     *
     * @param  Builder<Intervention>  $query
     * @return array{count: int, minutes: int, to_invoice_count: int, to_invoice_minutes: int, running: int}
     */
    public static function summary(Builder $query): array
    {
        $all = (clone $query)->get(['id', 'duration_minutes', 'billable']);
        $toInvoice = (clone $query)->toInvoice()->get(['id', 'duration_minutes']);

        return [
            'count' => $all->count(),
            'minutes' => (int) $all->sum('duration_minutes'),
            'to_invoice_count' => $toInvoice->count(),
            'to_invoice_minutes' => (int) $toInvoice->sum(fn (Intervention $intervention) => $intervention->billableMinutes()),
            'running' => $all->whereNull('duration_minutes')->count(),
        ];
    }

    /**
     * Technicians see their own interventions; "interventions.supervise" sees everyone's.
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can(Permission::InterventionsSupervise->value)) {
            $query->where('technician_id', $user->id);
        }
    }

    /**
     * Completed, billable and not yet in an invoice.
     *
     * @param  Builder<Intervention>  $query
     */
    public function scopeToInvoice(Builder $query): void
    {
        $query->whereNotNull('duration_minutes')->where('billable', true)->whereDoesntHave('invoiceLines');
    }

    /**
     * @param  Builder<Intervention>  $query
     */
    public function scopeWithStatus(Builder $query, ?string $status): void
    {
        match ($status) {
            InterventionStatus::Running->value => $query->whereNull('duration_minutes'),
            InterventionStatus::ToInvoice->value => $query->toInvoice(),
            InterventionStatus::Invoiced->value => $query->whereHas('invoiceLines'),
            InterventionStatus::NotBillable->value => $query->whereNotNull('duration_minutes')->where('billable', false)->whereDoesntHave('invoiceLines'),
            default => null,
        };
    }

    /**
     * @param  Builder<Intervention>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('organization', fn (Builder $query) => $query->withTrashed()->where('name', 'like', "%{$term}%")),
        ));
    }
}
