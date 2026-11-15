<?php

namespace App\Models;

use App\Enums\TariffYearStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TariffYear extends Model
{
    protected $fillable = ['year', 'source_file', 'status', 'message', 'is_active', 'counts', 'imported_at'];

    protected function casts(): array
    {
        return [
            'status' => TariffYearStatus::class,
            'is_active' => 'boolean',
            'counts' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /** Năm API dùng: năm chỉ định, nếu không thì năm đang dùng, nếu chưa chọn thì năm sẵn sàng mới nhất. */
    public static function current(?int $year = null): ?self
    {
        $ready = self::query()->ready();

        if ($year !== null) {
            return $ready->where('year', $year)->first();
        }

        return (clone $ready)->where('is_active', true)->first() ?? $ready->orderByDesc('year')->first();
    }

    public function scopeReady(Builder $query): void
    {
        $query->where('status', TariffYearStatus::Ready);
    }

    public function markActive(): void
    {
        self::query()->whereKeyNot($this->getKey())->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(HsRule::class)->orderBy('no');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(HsSection::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(HsChapter::class);
    }

    public function headings(): HasMany
    {
        return $this->hasMany(HsHeading::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(HsNode::class);
    }
}
