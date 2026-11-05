<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contratto servizi: services charged to a customer every period (canone). Periods are aligned to
 * the start date; a fee is due when its period starts (in advance) or ends (in arrears) and it is
 * neither in an invoice (SubscriptionPeriod) nor invoiced outside Prugna (up to billed_until).
 * Dates are calendar days: they carry no timezone.
 */
#[Fillable([
    'organization_id', 'title', 'billing_period', 'billing_mode', 'starts_on', 'ends_on', 'billed_until', 'payment_term_id',
    'owner_id', 'is_active', 'notes',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const MODE_ADVANCE = 'advance';

    public const MODE_ARREARS = 'arrears';

    /** Safety net for the period loop (100 years of monthly fees). */
    private const MAX_PERIODS = 1200;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'billed_until' => 'immutable_date',
            'is_active' => 'boolean',
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /**
     * @return HasMany<SubscriptionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SubscriptionLine::class)->orderBy('position');
    }

    /**
     * Fees already put in an invoice.
     *
     * @return HasMany<SubscriptionPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class)->orderBy('starts_on');
    }

    public static function modeLabel(string $mode): string
    {
        return $mode === self::MODE_ARREARS ? __('Posticipato') : __('Anticipato');
    }

    /**
     * @return list<array{value: string, title: string, subtitle: string}>
     */
    public static function modeOptions(): array
    {
        return [
            ['value' => self::MODE_ADVANCE, 'title' => self::modeLabel(self::MODE_ADVANCE), 'subtitle' => __('Si fattura all\'inizio del periodo')],
            ['value' => self::MODE_ARREARS, 'title' => self::modeLabel(self::MODE_ARREARS), 'subtitle' => __('Si fattura alla fine del periodo')],
        ];
    }

    /**
     * Taxable amount of one fee.
     */
    public function amount(): float
    {
        return round((float) $this->lines->sum(fn (SubscriptionLine $line) => $line->isItem() ? (float) $line->total : 0), 2);
    }

    /**
     * The n-th period (0 = the first), aligned to the start date.
     *
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}
     */
    public function period(int $index): array
    {
        $months = $this->billing_period->months();

        return [
            'starts_on' => $this->starts_on->addMonthsNoOverflow($index * $months),
            'ends_on' => $this->starts_on->addMonthsNoOverflow(($index + 1) * $months)->subDay(),
        ];
    }

    /**
     * Date on which the fee of a period is to be invoiced.
     *
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}  $period
     */
    public function dueDate(array $period): CarbonImmutable
    {
        return $this->billing_mode === self::MODE_ARREARS ? $period['ends_on'] : $period['starts_on'];
    }

    /**
     * Periods still to invoice, in order: the ones due by $until, or the next $limit ones when
     * $until is null (open-ended contracts have no last period).
     *
     * @return list<array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}>
     */
    public function unbilledPeriods(?CarbonInterface $until, int $limit = self::MAX_PERIODS): array
    {
        $billed = $this->periods->map(fn (SubscriptionPeriod $period) => $period->starts_on->toDateString())->flip();
        $periods = [];

        for ($index = 0; $index < self::MAX_PERIODS && count($periods) < $limit; $index++) {
            $period = $this->period($index);

            if ($this->ends_on !== null && $period['starts_on']->toDateString() > $this->ends_on->toDateString()) {
                break;
            }

            if ($until !== null && $this->dueDate($period)->toDateString() > $until->toDateString()) {
                break;
            }

            if ($this->billed_until !== null && $period['ends_on']->toDateString() <= $this->billed_until->toDateString()) {
                continue;
            }

            if (! $billed->has($period['starts_on']->toDateString())) {
                $periods[] = $period;
            }
        }

        return $periods;
    }

    /**
     * Fees due by the date (none when the contract is suspended).
     *
     * @return list<array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}>
     */
    public function duePeriods(CarbonInterface $date): array
    {
        return $this->is_active ? $this->unbilledPeriods($date) : [];
    }

    /**
     * The next fee to invoice, due or not.
     *
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}|null
     */
    public function nextPeriod(): ?array
    {
        return $this->is_active ? ($this->unbilledPeriods(null, 1)[0] ?? null) : null;
    }

    /**
     * "dicembre 2026", "anno 2027" or "dal 15/01/2026 al 14/04/2026".
     *
     * @param  array{starts_on: CarbonInterface, ends_on: CarbonInterface}  $period
     */
    public static function periodLabel(array $period): string
    {
        $start = CarbonImmutable::parse($period['starts_on']->toDateString())->locale('it');
        $end = CarbonImmutable::parse($period['ends_on']->toDateString());
        $wholeMonths = $start->day === 1 && $end->isLastOfMonth();

        return match (true) {
            $wholeMonths && $start->isSameMonth($end) => $start->translatedFormat('F Y'),
            $wholeMonths && $start->month === 1 && $end->month === 12 && $start->isSameYear($end) => __('anno :year', ['year' => $start->year]),
            default => __('dal :from al :to', ['from' => $start->format('d/m/Y'), 'to' => $end->format('d/m/Y')]),
        };
    }

    /**
     * Today as a calendar day in the display timezone.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(now(config('app.display_timezone'))->toDateString());
    }

    /**
     * active / upcoming / ended / suspended.
     */
    public function status(): string
    {
        $today = self::today()->toDateString();

        return match (true) {
            ! $this->is_active => 'suspended',
            $this->starts_on->toDateString() > $today => 'upcoming',
            $this->ends_on !== null && $this->ends_on->toDateString() < $today => 'ended',
            default => 'active',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('Attivo'),
            'upcoming' => __('Non ancora iniziato'),
            'ended' => __('Terminato'),
            default => __('Sospeso'),
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'upcoming' => 'info',
            'ended' => 'grey',
            default => 'warning',
        };
    }

    /**
     * Start date and billing period can't change once a fee is invoiced: the periods are aligned to them.
     */
    public function hasBilledPeriods(): bool
    {
        return $this->relationLoaded('periods') ? $this->periods->isNotEmpty() : $this->periods()->exists();
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhereHas('organization', fn (Builder $query) => $query->withTrashed()->where('name', 'like', "%{$term}%"))
                ->orWhereHas('lines', fn (Builder $query) => $query->where('description', 'like', "%{$term}%")),
        ));
    }
}
