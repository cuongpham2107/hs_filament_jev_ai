<?php

namespace App\Models;

use Database\Factories\TraCuuLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Lưu một lượt tra cứu từ danh sách sự kiện đã phát của HsLookup.
     *
     * @param  array{message: string, conversation_id?: ?string}  $input
     * @param  list<array<string, mixed>>  $sent
     */
    public static function recordFromEvents(array $input, int|string|null $userId, array $sent, int $durationMs): void
    {
        $byType = fn (string $type): array => array_values(array_filter($sent, fn (array $e): bool => $e['type'] === $type));
        $result = $byType('result')[0] ?? null;
        $error = $byType('error')[0]['message'] ?? null;

        try {
            self::query()->create([
                'user_id' => $userId,
                'message' => $input['message'],
                'conversation_id' => $byType('conversation')[0]['id'] ?? ($input['conversation_id'] ?? null),
                'is_follow_up' => ! empty($input['conversation_id']),
                'status' => $result === null || $error !== null ? 'error' : $result['status'],
                'ma_hs' => $result['code'] ?? null,
                'do_tin_cay' => $result['confidence'] ?? null,
                'can_cu' => implode("\n", array_column($byType('reasoning'), 'text')) ?: null,
                'the_ket_qua' => $result['markdown'] ?? null,
                'error' => $error,
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            Log::error('Tra cứu HS: không lưu được lịch sử', ['exception' => $e]);
        }
    }
}
