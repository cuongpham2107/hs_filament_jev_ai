<?php

namespace App\Http\Controllers\Api;

use App\Ai\HsLookup\HsLookup;
use App\Http\Controllers\Controller;
use App\Models\TraCuuLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * API chat tra cứu mã HS dành cho hệ thống bên ngoài (xác thực qua header x-api-key = APP_KEY).
 */
class ChatController extends Controller
{
    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
            'stream' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('stream')) {
            return $this->stream($input);
        }

        $sent = [];
        $startedAt = hrtime(true);
        $collect = function (array $event) use (&$sent): void {
            $sent[] = $event;
        };
        $debug = $request->boolean('debug');

        try {
            app(HsLookup::class)->run($input['message'], $input['conversation_id'] ?? null, $collect, $debug);
        } catch (Throwable $e) {
            Log::error('API Tra cứu HS: lỗi khi tra', ['exception' => $e]);
            $collect(['type' => 'error', 'message' => 'Không gọi được Jev. Kiểm tra TYPESAFE_API_KEY và kết nối tới TypeSafe.']);
        }

        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
        TraCuuLog::recordFromEvents($input, null, $sent, $durationMs);

        $byType = fn (string $type): array => array_values(array_filter($sent, fn (array $e): bool => $e['type'] === $type));
        $result = $byType('result')[0] ?? null;
        $error = $byType('error')[0]['message'] ?? null;
        $code = $result['code'] ?? null;

        if ($error !== null || $result === null) {
            return response()->json([
                'conversation_id' => $byType('conversation')[0]['id'] ?? ($input['conversation_id'] ?? null),
                'status' => 'error',
                'error' => $error ?? 'Không tra cứu được mã HS.',
                'can_cu' => array_values(array_column($byType('reasoning'), 'text')),
                'duration_ms' => $durationMs,
            ], 502);
        }

        $payload = [
            'conversation_id' => $byType('conversation')[0]['id'] ?? ($input['conversation_id'] ?? null),
            'status' => $result['status'],
            'ma_hs' => $code,
            'ma_hs_formatted' => $code ? substr($code, 0, 4).'.'.substr($code, 4, 2).'.'.substr($code, 6) : null,
            'do_tin_cay' => $result['confidence'] ?? null,
            'candidates' => $result['candidates'] ?? [],
            'suggestions' => $result['suggestions'] ?? [],
            'usage' => $result['usage'] ?? null,
            'can_cu' => array_values(array_column($byType('reasoning'), 'text')),
            'markdown' => $result['markdown'] ?? null,
            'duration_ms' => $durationMs,
        ];

        if ($debug) {
            $payload['debug'] = $byType('debug')[0]['data'] ?? null;
        }

        return response()->json($payload);
    }

    /** @param array{message: string, conversation_id?: ?string} $input */
    private function stream(array $input): StreamedResponse
    {
        return response()->stream(function () use ($input): void {
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
                Log::error('API Tra cứu HS (stream): lỗi khi tra', ['exception' => $e]);
                $send(['type' => 'error', 'message' => 'Không gọi được Jev. Kiểm tra TYPESAFE_API_KEY và kết nối tới TypeSafe.']);
            }
            $send(['type' => 'end']);
            TraCuuLog::recordFromEvents($input, null, $sent, intdiv(hrtime(true) - $startedAt, 1_000_000));
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }
}
