<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Lifecycle log of an app (activation steps, start/stop, errors) - kept after removal.
 */
#[Fillable(['app_id', 'level', 'message'])]
class AppLog extends Model
{
    public const UPDATED_AT = null;
}
