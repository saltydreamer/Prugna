<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Models\Concerns\HasPrimaryFlag;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'is_primary', 'address', 'postal_code', 'city', 'province', 'country', 'email', 'phone', 'notes'])]
#[Appends(['full_address'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasPrimaryFlag;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
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
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * "Via Roma 1, 20100 Milano (MI)" — the country is shown only when it is not Italy.
     *
     * @return Attribute<string, never>
     */
    protected function fullAddress(): Attribute
    {
        return Attribute::get(function (): string {
            $city = trim(implode(' ', array_filter([$this->postal_code, $this->city])));

            if ($this->province) {
                $city .= " ({$this->province})";
            }

            $parts = array_filter([$this->address, $city, $this->country !== 'IT' ? $this->country : null]);

            return implode(', ', $parts);
        });
    }
}
