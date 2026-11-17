<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nhóm 4 số. */
class HsHeading extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['notes' => 'array', 'subheadings' => 'array'];
    }

    public function tariffYear(): BelongsTo
    {
        return $this->belongsTo(TariffYear::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(HsChapter::class, 'hs_chapter_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(HsNode::class)->orderBy('position');
    }
}
