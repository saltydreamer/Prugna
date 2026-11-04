<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A callback received from the intermediary (kept as received, for the history and to reprocess it).
 */
#[Fillable(['event', 'type', 'uuid', 'sdi_transmission_id', 'received_invoice_id', 'payload', 'result', 'error'])]
class SdiEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SdiTransmission, $this>
     */
    public function transmission(): BelongsTo
    {
        return $this->belongsTo(SdiTransmission::class, 'sdi_transmission_id');
    }

    /**
     * @return BelongsTo<ReceivedInvoice, $this>
     */
    public function receivedInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivedInvoice::class);
    }
}
