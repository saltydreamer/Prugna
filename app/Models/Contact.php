<?php

namespace App\Models;

use App\Models\Concerns\HasPrimaryFlag;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['location_id', 'first_name', 'last_name', 'job_title', 'email', 'phone', 'mobile', 'is_primary', 'notes'])]
#[Appends(['full_name'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasPrimaryFlag;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Every word must match the name, email, phone numbers or organization name
     * ("mario rossi" finds Mario Rossi).
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $words = preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $query->where(fn (Builder $query) => $query
                ->where('first_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%")
                ->orWhere('email', 'like', "%{$word}%")
                ->orWhere('phone', 'like', "%{$word}%")
                ->orWhere('mobile', 'like', "%{$word}%")
                ->orWhereHas('organization', fn (Builder $query) => $query->where('name', 'like', "%{$word}%")));
        }
    }
}
