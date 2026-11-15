<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phần (I–XXI) và chú giải Phần. */
class HsSection extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['notes' => 'array'];
    }

    public function tariffYear(): BelongsTo
    {
        return $this->belongsTo(TariffYear::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(HsChapter::class);
    }
}
