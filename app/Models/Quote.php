<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\QuoteStatus;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id', 'contact_id', 'owner_id', 'customer', 'title', 'issue_date', 'valid_until',
    'payment_term_id', 'payment_terms', 'intro', 'terms', 'internal_notes',
])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'customer' => 'array',
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'decided_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote): void {
            $quote->public_token ??= Str::random(48);
            $quote->status ??= QuoteStatus::Draft;
        });
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
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
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    /**
     * Sent and still valid: the customer can accept or reject it online.
     */
    public function isOpen(): bool
    {
        return $this->status === QuoteStatus::Sent && ! $this->valid_until->isPast();
    }

    /**
     * Quotes visible to the user: their own, or all with the "supervise" permission.
     *
     * @param  Builder<Quote>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can(Permission::QuotesSupervise->value)) {
            $query->where('owner_id', $user->id);
        }
    }

    /**
     * @param  Builder<Quote>  $query
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

    public function markSent(): void
    {
        $this->forceFill([
            'status' => QuoteStatus::Sent,
            'sent_at' => $this->sent_at ?? now(),
        ])->save();
    }

    /**
     * Records the customer's (or our) decision.
     */
    public function decide(bool $accepted, ?string $name = null, ?string $note = null, ?string $ip = null): void
    {
        $this->forceFill([
            'status' => $accepted ? QuoteStatus::Accepted : QuoteStatus::Rejected,
            'decided_at' => now(),
            'decision_name' => $name,
            'decision_note' => $note,
            'decision_ip' => $ip,
        ])->save();
    }

    /**
     * Back to draft to be edited and sent again.
     */
    public function reopen(): void
    {
        $this->forceFill([
            'status' => QuoteStatus::Draft,
            'decided_at' => null,
            'decision_name' => null,
            'decision_note' => null,
            'decision_ip' => null,
            'reminder_sent_at' => null,
        ])->save();
    }

    public function publicUrl(): string
    {
        return route('quotes.public.show', $this->public_token);
    }
}
