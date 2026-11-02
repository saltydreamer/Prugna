<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw storage of App\Support\Settings: use the service, not this model.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    //
}
