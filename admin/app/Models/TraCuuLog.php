<?php

namespace App\Models;

use Database\Factories\TraCuuLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lượt tra cứu trong khung chat: mô tả người dùng nhập và kết quả workflow trả về. */
class TraCuuLog extends Model
{
    /** @use HasFactory<TraCuuLogFactory> */
    use HasFactory;

    public const STATUS_LABELS = ['ok' => 'Đã xác định', 'can_bo_sung' => 'Cần bổ sung', 'error' => 'Lỗi'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_follow_up' => 'boolean', 'do_tin_cay' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function formattedCode(): ?string
    {
        return $this->ma_hs ? substr($this->ma_hs, 0, 4).'.'.substr($this->ma_hs, 4, 2).'.'.substr($this->ma_hs, 6) : null;
    }
}
