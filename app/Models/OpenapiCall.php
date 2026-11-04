<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Every request made to Openapi, with the price it costs (from the price list in the settings):
 * the basis of the cost summary page.
 */
#[Fillable(['environment', 'method', 'endpoint', 'http_status', 'success', 'price', 'duration_ms', 'error', 'invoice_id', 'user_id', 'created_at'])]
class OpenapiCall extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'price' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }
}
