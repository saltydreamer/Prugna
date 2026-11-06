<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Several expense documents uploaded together and recorded in the background: the files are
 * uploaded one by one, then started (App\Jobs\ProcessExpenseImportItem); done when no item is waiting.
 */
class ExpenseImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ExpenseImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ExpenseImportItem::class);
    }

    public function isStarted(): bool
    {
        return $this->started_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
