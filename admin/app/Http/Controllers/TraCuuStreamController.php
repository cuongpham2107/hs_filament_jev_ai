<?php

namespace App\Http\Controllers;

use App\Services\DifyRunEvents;
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
        $user = 'admin-'.$request->user()->getKey();

        return response()->stream(function () use ($input, $user): void {
            $events = new DifyRunEvents;
            $send = function (array $event): void {
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $this->run($input['message'], $input['conversation_id'] ?? null, $user, fn (array $raw) => array_map($send, $events->map($raw)));
            } catch (Throwable $e) {
                Log::error('Tra cứu HS: gọi Dify lỗi', ['exception' => $e]);
                $send(['type' => 'error', 'message' => 'Không gọi được Dify. Kiểm tra Dify đang chạy và HS_DIFY_KEY.']);
            }
            $send(['type' => 'end']);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
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
