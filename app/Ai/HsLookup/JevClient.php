<?php

namespace App\Ai\HsLookup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

/**
 * Gọi Jev (TypeSafe System One) qua laravel/ai, trả về câu trả lời dạng mảng giống plugin Dify để phần đọc kết quả
 * giữ nguyên: choice -> {choice, probabilities: {tên lựa chọn (chuỗi) => p}}, noul -> {noul: p}.
 * SDK chỉ tự chuyển nhà cung cấp khi bị giới hạn / quá tải, không thử lại khi quá thời gian chờ, nên thử lại ở đây.
 */
class JevClient
{
    public function __construct(
        private int $timeout = 60,
        private int $attempts = 3,
        private int $retryDelayMs = 1000,
    ) {}

    /**
     * @param  string|array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $questions  khoá câu hỏi => {type, instructions, criteria}
     * @return array<string, array<string, mixed>>
     */
    public function ask(string|array $state, array $questions): array
    {
        $startedAt = hrtime(true);
        $attempt = 0;

        $response = retry($this->attempts, function () use ($state, $questions, &$attempt) {
            $attempt++;

            return Classification::of($state)
                ->questions(array_map(fn (array $q): WireQuestion => new WireQuestion($q), $questions))
                ->timeout($this->timeout)
                ->classify(config('hs.jev_provider'), config('hs.jev_model'));
        }, $this->retryDelayMs, function ($e) use (&$attempt): bool {
            $shouldRetry = $e instanceof ConnectionException;
            if ($shouldRetry && config('hs.debug_log')) {
                $this->log('warning', '[Jev] ConnectionException, thử lại', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            return $shouldRetry;
        });

        $answers = [];
        foreach (array_keys($questions) as $key) {
            $answer = $response->answer($key);
            $answers[$key] = match (true) {
                $answer instanceof ChoiceAnswer => [
                    'choice' => $answer->choice,
                    // khoá "87", "15" thành số nguyên trong mảng PHP; đọc bằng khoá chuỗi vẫn được, khi duyệt khoá thì ép (string)
                    'probabilities' => $answer->probabilities,
                    'confidence' => $answer->confidence,
                ],
                $answer instanceof BooleanAnswer => ['noul' => $answer->probability],
                default => [],
            };
        }

        if (config('hs.debug_log')) {
            $encodedState = is_string($state) ? $state : json_encode($state, JSON_UNESCAPED_UNICODE);
            $this->log('debug', '[Jev] API call', [
                'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
                'attempts' => $attempt,
                'provider' => config('hs.jev_provider'),
                'model' => config('hs.jev_model') ?: 'jev-latest',
                'state_bytes' => strlen((string) $encodedState),
                'question_count' => count($questions),
                'question_keys' => array_keys($questions),
            ]);
        }

        return $answers;
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('hs.debug_log_channel');
        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->{$level}($message, $context);

            return;
        }

        Log::{$level}($message, $context);
    }
}
