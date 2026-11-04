<?php

namespace App\Models;

use App\Enums\SdiStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to send an issued invoice to the SDI (a rejected invoice is sent again with a new file).
 */
#[Fillable(['invoice_id', 'environment', 'uuid', 'progressive', 'filename', 'status', 'sdi_file_id', 'errors', 'message', 'sent_at', 'outcome_at', 'user_id'])]
class SdiTransmission extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SdiStatus::class,
            'errors' => 'array',
            'sent_at' => 'datetime',
            'outcome_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SdiEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SdiEvent::class)->orderBy('id');
    }
}
