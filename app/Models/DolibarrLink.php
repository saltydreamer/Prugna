<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A Dolibarr row imported into Prugna ("societe.customer" #12 → Organization #40).
 */
class DolibarrLink extends Model
{
    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
