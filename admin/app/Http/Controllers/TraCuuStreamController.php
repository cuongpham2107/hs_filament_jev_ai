<?php

namespace App\Http\Controllers;

use App\Ai\HsLookup\HsLookup;
use App\Models\TraCuuLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Khung chat tra cứu trong admin gọi vào đây; Laravel chạy App\Ai\HsLookup (Jev qua laravel/ai + đọc biểu thuế từ CSDL)
 * và phát tiến trình / căn cứ / thẻ kết quả dưới dạng Server-Sent Events.
 */
class TraCuuStreamController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);
        $userId = $request->user()->getKey();

        return response()->stream(function () use ($input, $userId): void {
            $sent = [];
            $startedAt = hrtime(true);
            $send = function (array $event) use (&$sent): void {
                $sent[] = $event;
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                app(HsLookup::class)->run($input['message'], $input['conversation_id'] ?? null, $send);
            } catch (Throwable $e) {
                Log::error('Tra cứu HS: lỗi khi tra', ['exception' => $e]);
                $send(['type' => 'error', 'message' => 'Không gọi được Jev. Kiểm tra TYPESAFE_API_KEY và kết nối tới TypeSafe.']);
            }
            $send(['type' => 'end']);
            $this->saveLog($input, $userId, $sent, intdiv(hrtime(true) - $startedAt, 1_000_000));
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    /**
     * Lưu lượt tra cứu (trang 'Lịch sử tra cứu') từ chính các sự kiện đã gửi cho khung chat.
     *
     * @param  array{message: string, conversation_id?: ?string}  $input
     * @param  list<array<string, mixed>>  $sent
     */
    private function saveLog(array $input, int|string $userId, array $sent, int $durationMs): void
    {
        $byType = fn (string $type): array => array_values(array_filter($sent, fn (array $e): bool => $e['type'] === $type));
        $result = $byType('result')[0] ?? null;
        $error = $byType('error')[0]['message'] ?? null;

        try {
            TraCuuLog::query()->create([
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
            Log::error('Tra cứu HS: không lưu được lịch sử', ['exception' => $e]);  // không làm hỏng câu trả lời đã gửi
        }
    }
}
