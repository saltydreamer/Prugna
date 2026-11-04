<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\SdiStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'issue_date', 'organization_id', 'contact_id', 'owner_id', 'quote_id', 'order_id', 'related_invoice_id',
    'title', 'customer_reference', 'customer_reference_date', 'cig', 'cup', 'payment_term_id', 'notes', 'internal_notes',
    'split_payment', 'apply_withholding', 'apply_pension',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'customer' => 'array',
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'customer_reference_date' => 'date',
            'due_date' => 'date',
            'split_payment' => 'boolean',
            'apply_withholding' => 'boolean',
            'apply_pension' => 'boolean',
            'pension_withholding' => 'boolean',
            'withholding_rate' => 'decimal:2',
            'withholding_amount' => 'decimal:2',
            'pension_rate' => 'decimal:2',
            'pension_vat_rate' => 'decimal:2',
            'pension_amount' => 'decimal:2',
            'stamp_duty_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'sdi_status' => SdiStatus::class,
        ];
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
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
     * Latest transmission to the SDI.
     *
     * @return BelongsTo<SdiTransmission, $this>
     */
    public function sdiTransmission(): BelongsTo
    {
        return $this->belongsTo(SdiTransmission::class);
    }

    /**
     * Every attempt to send the invoice to the SDI, latest first.
     *
     * @return HasMany<SdiTransmission, $this>
     */
    public function sdiTransmissions(): HasMany
    {
        return $this->hasMany(SdiTransmission::class)->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Credit note → credited invoice.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'related_invoice_id');
    }

    /**
     * For a deposit invoice: the lines of balance invoices that deduct it.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function deductedBy(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'deducts_invoice_id');
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
        return $this->status === InvoiceStatus::Draft;
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Bozza n. :id', ['id' => $this->id]);
    }

    /**
     * @param  Builder<Invoice>  $query
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
