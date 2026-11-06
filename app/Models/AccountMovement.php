<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money in (positive) or out (negative) of an account: collection of an invoice, payment of an
 * expense, side of a transfer, or an entry without a document (fees, taxes, contributions...).
 */
#[Fillable(['financial_account_id', 'date', 'amount', 'description', 'invoice_id', 'expense_id', 'category_id', 'business_line_id', 'transfer_uuid', 'user_id'])]
class AccountMovement extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<AccountingCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountingCategory::class);
    }

    /**
     * @return BelongsTo<BusinessLine, $this>
     */
    public function businessLine(): BelongsTo
    {
        return $this->belongsTo(BusinessLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTransfer(): bool
    {
        return $this->transfer_uuid !== null;
    }

    /**
     * Written by hand (not a collection, a payment or a transfer): can be edited and deleted from the account.
     */
    public function isManual(): bool
    {
        return $this->invoice_id === null && $this->expense_id === null && ! $this->isTransfer();
    }
}
