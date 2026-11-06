<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item of an estimate at cost (VAT included when it can't be deducted, as in the flat-rate regime).
 */
#[Fillable(['position', 'category', 'description', 'url', 'quantity', 'unit_cost', 'note'])]
class EstimateItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function cost(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 2);
    }
}
