<?php

namespace App\Http\Controllers;

use App\Models\TraCuuLog;
use App\Services\DifyRunEvents;
use App\Services\HsLookup\HsLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Khung chat tra cứu trong admin gọi vào đây; Laravel gọi chatflow Dify (/chat-messages, streaming) bằng key phía server
 * và chuyển tiếp sự kiện đã gom (DifyRunEvents) dưới dạng Server-Sent Events.
 */
class TraCuuStreamController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);
        // Dify chỉ cho dùng hội thoại của đúng user này, nên conversation_id người khác gửi lên không đọc được
        $userId = $request->user()->getKey();
        $user = 'admin-'.$userId;

        return response()->stream(function () use ($input, $user, $userId): void {
            $events = new DifyRunEvents;
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

            $laravel = config('hs.lookup_engine') === 'laravel';
            try {
                if ($laravel) {
                    app(HsLookup::class)->run($input['message'], $input['conversation_id'] ?? null, $send);
                } else {
                    $this->run($input['message'], $input['conversation_id'] ?? null, $user, fn (array $raw) => array_map($send, $events->map($raw)));
                }
            } catch (Throwable $e) {
                Log::error('Tra cứu HS: lỗi khi tra', ['engine' => $laravel ? 'laravel' : 'dify', 'exception' => $e]);
                $send(['type' => 'error', 'message' => $laravel
                    ? 'Không gọi được Jev. Kiểm tra TYPESAFE_API_KEY và kết nối tới TypeSafe.'
                    : 'Không gọi được Dify. Kiểm tra Dify đang chạy và HS_DIFY_KEY.']);
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

    /** @param callable(array<string, mixed>): mixed $onEvent */
    private function run(string $message, ?string $conversationId, string $user, callable $onEvent): void
    {
        $response = Http::withToken((string) config('hs.dify_key'))
            ->withOptions(['stream' => true])
            ->timeout(config('hs.dify_timeout'))
            ->throw()
            ->post(rtrim(config('hs.dify_url'), '/').'/chat-messages', [
                'inputs' => (object) [],
                'query' => $message,
                'response_mode' => 'streaming',
                'conversation_id' => $conversationId ?? '',
                'user' => $user,
            ]);

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                if (str_starts_with($line, 'data:')) {
                    $event = json_decode(trim(substr($line, 5)), true);
                    if (is_array($event)) {
                        $onEvent($event);
                    }
                }
            }
        }
    }
}
