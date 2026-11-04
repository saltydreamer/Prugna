<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost (supplier order line or expense line) charged back by an invoice line.
 */
#[Fillable(['invoice_line_id', 'purchase_order_line_id', 'expense_line_id'])]
class InvoiceLineCost extends Model
{
    /**
     * @return BelongsTo<InvoiceLine, $this>
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * "po-12" / "ex-7", as used by the frontend and by App\Actions\Purchases\RebillCosts.
     */
    public function key(): string
    {
        return $this->expense_line_id ? 'ex-'.$this->expense_line_id : 'po-'.$this->purchase_order_line_id;
    }
}
