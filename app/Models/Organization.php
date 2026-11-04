<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'type', 'role', 'vat_number', 'tax_code', 'sdi_code', 'split_payment', 'payment_term_id', 'email', 'pec', 'phone', 'website', 'notes'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'customer',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'role' => OrganizationRole::class,
            'split_payment' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @return HasOne<Location, $this>
     */
    public function primaryLocation(): HasOne
    {
        return $this->hasOne(Location::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Intervention, $this>
     */
    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class);
    }

    /**
     * @return HasOne<Contact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    /**
     * Payment term proposed in this customer's documents.
     *
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /**
     * Prices agreed with this customer.
     *
     * @return HasMany<CustomerPrice, $this>
     */
    public function customerPrices(): HasMany
    {
        return $this->hasMany(CustomerPrice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Search by name, VAT number, tax code, email or city of any location.
     *
     * @param  Builder<Organization>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('vat_number', 'like', "%{$term}%")
                ->orWhere('tax_code', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhereHas('locations', fn (Builder $query) => $query->where('city', 'like', "%{$term}%")),
        ));
    }

    public function isSupplier(): bool
    {
        return $this->role === OrganizationRole::Supplier;
    }

    /**
     * Whether quotes, orders, invoices or supplier orders refer to it: then its role can't change.
     */
    public function hasDocuments(): bool
    {
        return Quote::query()->where('organization_id', $this->id)->exists()
            || Order::query()->where('organization_id', $this->id)->exists()
            || Invoice::query()->where('organization_id', $this->id)->exists()
            || Intervention::query()->where('organization_id', $this->id)->exists()
            || PurchaseOrder::query()->where('supplier_id', $this->id)->orWhere('customer_id', $this->id)->exists();
    }

    /**
     * @param  Builder<Organization>  $query
     */
    public function scopeCustomers(Builder $query): void
    {
        $query->where('role', OrganizationRole::Customer->value);
    }

    /**
     * @param  Builder<Organization>  $query
     */
    public function scopeSuppliers(Builder $query): void
    {
        $query->where('role', OrganizationRole::Supplier->value);
    }
}
