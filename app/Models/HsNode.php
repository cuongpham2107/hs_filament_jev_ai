<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một dòng trong cây của nhóm: phân nhóm, dòng tiêu đề gạch ngang, hoặc mã 8 số (có path, thuế, rủi ro). */
class HsNode extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax' => 'array', 'risk' => 'array'];
    }

    /** Chỉ các dòng mã 8 số (dòng hàng). */
    public function scopeTariffLines(Builder $query): void
    {
        $query->whereNotNull('path');
    }

    public function formattedCode(): string
    {
        $code = (string) $this->code;

        return strlen($code) === 8
            ? substr($code, 0, 4).'.'.substr($code, 4, 2).'.'.substr($code, 6)
            : substr($code, 0, 4).'.'.substr($code, 4);
    }

    public function tariffYear(): BelongsTo
    {
        return $this->belongsTo(TariffYear::class);
    }

    public function heading(): BelongsTo
    {
        return $this->belongsTo(HsHeading::class, 'hs_heading_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }
}
