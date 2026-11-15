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
            TraCuuLog::recordFromEvents($input, $userId, $sent, intdiv(hrtime(true) - $startedAt, 1_000_000));
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }
}
