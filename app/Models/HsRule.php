<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một trong 6 quy tắc tổng quát (sheet 6QT). */
class HsRule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function tariffYear(): BelongsTo
    {
        return $this->belongsTo(TariffYear::class);
    }
}
