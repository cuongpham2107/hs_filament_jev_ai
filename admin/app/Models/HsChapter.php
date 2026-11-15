<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chương và chú giải Chương. */
class HsChapter extends Model
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(HsSection::class, 'hs_section_id');
    }

    public function headings(): HasMany
    {
        return $this->hasMany(HsHeading::class);
    }
}
