<?php

namespace App\Ai\HsLookup;

use App\Ai\Agents\HsRationaleAgent;
use App\Models\HsHeading;
use App\Models\HsNode;
use App\Models\TariffYear;
use App\Models\TraCuuLog;
use App\Services\TariffDocuments;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tra cứu mã HS trong Laravel (`App\Ai\HsLookup`): gọi Jev qua `laravel/ai`, đọc cây và thuế thẳng từ CSDL.
 * Phát các sự kiện SSE cho khung chat và lưu lịch sử:
 * conversation, step (bước Plan), reasoning (căn cứ), result (thẻ markdown, mã, độ tin cậy).
 */
class HsLookup
{
    /** Xác suất chương đứng đầu dưới mức này thì hỏi lại chương kèm chú giải loại trừ. */
    private const CHAPTER_SURE = 0.6;

    /** Chỉ nhận kết quả hỏi lại khi nó chắc chắn hơn lượt đầu ít nhất mức này; lệch ít thì giữ lượt đầu. */
    private const CHAPTER_RETRY_GAIN = 0.15;

    /** @var array<string, string> các bước Plan hiển thị trên khung chat */
    public const STEPS = [
        'chuong' => 'Chọn chương',
        'nhom' => 'Chọn nhóm 4 số',
        'cay' => 'Lấy cây biểu thuế của các nhóm',
        'di_cay' => 'Chốt nhóm (QT 1–5) và đi cây từng cấp (QT 6)',
        'ket_luan' => 'Kết luận mã',
        'the' => 'Tra thuế và dựng thẻ kết quả',
    ];

    public function __construct(
        private JevClient $jev,
        private TariffDocuments $documents,
        private LookupQuestions $questions,
        private LookupResult $result,
        private ResultCard $card,
        private HsRationaleAgent $rationale,
    ) {}

    /** @param callable(array<string, mixed>): void $emit */
    public function run(string $message, ?string $conversationId, callable $emit, bool $debug = false): void
    {
        $startedAt = hrtime(true);
        $this->jev->resetUsage();
        $year = TariffYear::current() ?? throw new RuntimeException('Chưa có năm biểu thuế nào sẵn sàng.');
        $reasoning = new LookupReasoning($year);
        $description = $this->withEarlierTurns($message, $conversationId);
        $resolvedConversationId = $conversationId ?? (string) Str::uuid();
        $traceId = substr($resolvedConversationId, 0, 8);
        $emit(['type' => 'conversation', 'id' => $resolvedConversationId]);
        $step = $this->stepper($emit);

        $this->debugLog("[Jev][{$traceId}] Bắt đầu tra cứu", [
            'conversation_id' => $resolvedConversationId,
            'year' => $year->year,
            'message' => $message,
            'is_follow_up' => $conversationId !== null,
            'description' => $description !== $message ? $description : null,
        ]);

        $catalog = self::plain($this->documents->catalog($year));
        $chapters = $step('chuong', function () use ($description, $catalog, $traceId): array {
            $answer = $this->jev->ask($description, $this->questions->chapter($catalog));
            $sorted = LookupQuestions::sortedProbabilities($answer['chuong'] ?? []);
            $top = (float) ($sorted[0][1] ?? 0.0);
            $chosen = LookupQuestions::chosenChapters($answer['chuong'] ?? []);
            if ($top >= self::CHAPTER_SURE || $chosen === []) {
                return $answer;
            }

            // chưa chắc chương: hỏi lại kèm chú giải loại trừ của các chương đang nghi (Quy tắc 1)
            $retry = $this->questions->chapterRetry(
                array_map(fn (array $c): string => (string) $c[0], $chosen), $catalog, $description);
            $second = $this->jev->ask($retry['state'], $retry['questions']);
            $secondTop = (float) (LookupQuestions::sortedProbabilities($second['chuong'] ?? [])[0][1] ?? 0.0);
            $accepted = $secondTop >= $top + self::CHAPTER_RETRY_GAIN;
            $this->debugLog("[Jev][{$traceId}] Bước 1b - Chọn lại chương kèm chú giải loại trừ", [
                'first_top' => $this->topProbs($answer['chuong'] ?? [], 3),
                'retry_chapters' => array_map(fn (array $c): string => (string) $c[0], $chosen),
                'second_top' => $this->topProbs($second['chuong'] ?? [], 3),
                'accepted' => $accepted,
            ]);

            return $accepted ? $second : $answer;
        });

        $candidates = $this->questions->headings($chapters['chuong'] ?? [], $catalog, $description);
        $this->debugLog("[Jev][{$traceId}] Bước 1 - Chọn chương", [
            'top_chapters' => $this->topProbs($chapters['chuong'] ?? [], 5),
            'chosen' => array_map(
                fn (array $c): array => ['chapter' => $c[0], 'prob' => round($c[1], 4)],
                $candidates['chosen'],
            ),
            'candidate_headings_count' => $candidates['candidates'],
        ]);
        $emit(['type' => 'reasoning', 'text' => $reasoning->chapters(
            implode(', ', array_map(fn (array $c): string => "{$c[0]} (".LookupResult::percent($c[1]).')', $candidates['chosen'])),
            $candidates['candidates'])]);
        $headingAnswer = $step('nhom', fn (): array => $this->jev->ask($candidates['state'], $candidates['questions']));
        $picked = $this->questions->pickHeadings($headingAnswer['nhom'] ?? []);
        $this->debugLog("[Jev][{$traceId}] Bước 2 - Chọn nhóm 4 số", [
            'top_headings' => $this->topProbs($headingAnswer['nhom'] ?? [], 10),
            'picked_headings' => $picked,
        ]);
        $emit(['type' => 'reasoning', 'text' => $reasoning->headings($picked)]);

        $trees = $step('cay', fn (): array => HsHeading::query()->whereBelongsTo($year)->whereIn('code', $picked)->get()
            ->sortBy(fn (HsHeading $h): int => array_search($h->code, $picked, true))
            ->map(fn (HsHeading $h): array => self::plain($this->documents->heading($h)))->values()->all());

        $asked = $this->questions->tree($trees, $description, $this->documents->rules($year));
        $answers = $step('di_cay', fn (): array => $this->jev->ask($asked['state'], $asked['questions']));
        $trees = array_values(array_filter($trees, fn (array $t): bool => ! in_array((string) $t['code'], $asked['skipped'], true)));
        $stepProbs = $this->buildStepProbs($asked, $answers);
        $this->debugLog("[Jev][{$traceId}] Bước 4 - Chốt nhóm & đi cây", [
            'skipped_headings' => $asked['skipped'],
            'co_nhom_phu_hop' => $answers['co_nhom_phu_hop']['noul'] ?? null,
            'headings_step4' => $this->topProbs($answers['nhom'] ?? [], 10),
            'step_probs' => $stepProbs,
        ]);

        $read = $step('ket_luan', fn (): array => $this->result->read($answers, $trees));
        $topCodes = $this->buildTopCodes($read);
        $this->debugLog("[Jev][{$traceId}] Bước 5 - Kết luận", [
            'status' => $read['trang_thai'],
            'ma_hs' => $read['ma_hs'],
            'do_tin_cay' => $read['do_tin_cay'],
            'cac_buoc' => $read['cac_buoc'],
            'can_lam_ro' => $read['trang_thai'] !== 'ok' ? $read['can_lam_ro'] : null,
            'top_codes' => array_slice($topCodes, 0, 5),
            'total_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ]);
        $emit(['type' => 'reasoning', 'text' => $reasoning->conclusion($read)]);

        $taxes = [];
        $markdown = $step('the', function () use ($read, $trees, $year, $description, &$taxes): string {
            $codes = array_map(fn (array $c): string => (string) $c[0], $read['candidates']);
            $lines = HsNode::query()->whereBelongsTo($year)->whereIn('code', $codes)->get()->keyBy('code');
            foreach ($codes as $code) {
                $taxes[$code] = isset($lines[$code]) ? self::plain($this->documents->tax($lines[$code])) : [];
            }
            if ($read['trang_thai'] !== 'ok') {
                return $this->card->needsMoreInfo($read, $description, $taxes);
            }
            $rationale = $this->rationale->explain($description, $read, $trees);

            return $this->card->ok($read, $taxes[$read['ma_hs']] ?? [], $description, $year->year, $rationale, $taxes);
        });
        $emit(['type' => 'result', 'status' => $read['trang_thai'], 'markdown' => $markdown,
            'code' => strlen($read['ma_hs']) === 8 ? $read['ma_hs'] : null, 'confidence' => $read['do_tin_cay'],
            'suggestions' => $read['goi_y'],
            'usage' => $this->jev->usage(),
            'candidates' => array_map(fn (array $c): array => [
                'ma_hs' => (string) $c[0], 'prob' => round($c[1], 4), 'path' => $read['paths'][$c[0]] ?? '',
            ], $read['candidates'])]);

        if ($debug) {
            $emit(['type' => 'debug', 'data' => $this->buildDebugData(
                $candidates,
                $headingAnswer,
                $picked,
                $asked,
                $answers,
                $read,
                $stepProbs,
                $topCodes,
            )]);
        }
    }

    /**
     * Chạy một bước của Plan: phát running, chạy, phát done (hoặc error rồi ném lại lỗi).
     *
     * @param  callable(array<string, mixed>): void  $emit
     */
    private function stepper(callable $emit): callable
    {
        return function (string $id, callable $work) use ($emit): mixed {
            $emit(['type' => 'step', 'id' => $id, 'status' => 'running']);
            try {
                $result = $work();
            } catch (\Throwable $e) {
                $emit(['type' => 'step', 'id' => $id, 'status' => 'error']);

                throw $e;
            }
            $emit(['type' => 'step', 'id' => $id, 'status' => 'done']);

            return $result;
        };
    }

    /**
     * Trả lời bổ sung: ghép các lượt 'cần bổ sung' liền trước trong cùng hội thoại (sau lượt 'ok' gần nhất) với câu mới,
     * như biến mo_ta_truoc của chatflow: "mô tả\nThông tin bổ sung: ...".
     */
    private function withEarlierTurns(string $message, ?string $conversationId): string
    {
        if ($conversationId === null) {
            return $message;
        }
        $earlier = [];
        foreach (TraCuuLog::query()->where('conversation_id', $conversationId)->orderBy('id')->get(['message', 'status']) as $log) {
            $earlier = match ($log->status) {
                'ok' => [],
                'can_bo_sung' => [...$earlier, $log->message],
                default => $earlier,  // lượt lỗi không tính
            };
        }

        return implode("\nThông tin bổ sung: ", [...$earlier, $message]);
    }

    /**
     * Tổng hợp xác suất thô và danh sách mã ứng viên mà Jev trả về ở các bước để phân tích.
     *
     * @param  array<string, mixed>  $candidates
     * @param  array<string, mixed>  $headingAnswer
     * @param  list<string>  $picked
     * @param  array<string, mixed>  $asked
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $read
     * @param  array<string, list<array{option: string, label: string, prob: float}>>  $stepProbs
     * @param  list<array{ma_hs: string, ma_hs_formatted: string, prob: float, path: string}>  $topCodes
     * @return array<string, mixed>
     */
    private function buildDebugData(
        array $candidates,
        array $headingAnswer,
        array $picked,
        array $asked,
        array $answers,
        array $read,
        array $stepProbs,
        array $topCodes,
    ): array {
        return [
            'chapters' => array_map(
                fn (array $c): array => ['chapter' => $c[0], 'prob' => round($c[1], 4)],
                $candidates['chosen'] ?? [],
            ),
            'headings_step2' => $this->topProbs($headingAnswer['nhom'] ?? [], 10),
            'picked_headings' => $picked,
            'skipped_headings' => $asked['skipped'] ?? [],
            'headings_step4' => $this->topProbs($answers['nhom'] ?? []),
            'co_nhom_phu_hop' => $answers['co_nhom_phu_hop']['noul'] ?? null,
            'cac_buoc' => $read['cac_buoc'] ?? '',
            'can_lam_ro' => $read['can_lam_ro'] ?? '',
            'top_codes' => $topCodes,
            'step_probs' => $stepProbs,
        ];
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array{code: string, prob: float}>
     */
    private function topProbs(array $answer, ?int $limit = null): array
    {
        $sorted = LookupQuestions::sortedProbabilities($answer);
        if ($limit !== null) {
            $sorted = array_slice($sorted, 0, $limit);
        }

        return array_map(
            fn (array $pair): array => ['code' => $pair[0], 'prob' => round($pair[1], 4)],
            $sorted,
        );
    }

    /**
     * @param  array<string, mixed>  $asked
     * @param  array<string, mixed>  $answers
     * @return array<string, list<array{option: string, label: string, prob: float}>>
     */
    private function buildStepProbs(array $asked, array $answers): array
    {
        $stepProbs = [];
        foreach ($asked['questions'] ?? [] as $key => $question) {
            if (! is_string($key) || $key === 'nhom' || $key === 'co_nhom_phu_hop') {
                continue;
            }
            $criteria = $question['criteria'] ?? [];
            $options = [];
            foreach (LookupQuestions::sortedProbabilities($answers[$key] ?? []) as [$optKey, $prob]) {
                if ($prob < 0.01) {
                    continue;
                }
                $options[] = [
                    'option' => $optKey,
                    'label' => isset($criteria[$optKey]) ? LookupQuestions::short((string) $criteria[$optKey]) : $optKey,
                    'prob' => round($prob, 4),
                ];
            }
            $stepProbs[$key] = $options;
        }

        return $stepProbs;
    }

    /**
     * @param  array<string, mixed>  $read
     * @return list<array{ma_hs: string, ma_hs_formatted: string, prob: float, path: string}>
     */
    private function buildTopCodes(array $read): array
    {
        return array_map(fn (array $pair): array => [
            'ma_hs' => $pair[0],
            'ma_hs_formatted' => substr($pair[0], 0, 4).'.'.substr($pair[0], 4, 2).'.'.substr($pair[0], 6),
            'prob' => round($pair[1], 4),
            'path' => $read['paths'][$pair[0]] ?? '',
        ], $read['ranked'] ?? []);
    }

    /** @param array<string, mixed> $context */
    private function debugLog(string $message, array $context = []): void
    {
        if (! config('hs.debug_log')) {
            return;
        }

        $filtered = array_filter($context, fn (mixed $v): bool => $v !== null);
        $channel = config('hs.debug_log_channel');
        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->debug($message, $filtered);

            return;
        }

        Log::debug($message, $filtered);
    }

    /** Mảng/đối tượng lồng nhau (TariffDocuments trả object cho map rỗng) -> mảng thuần. */
    private static function plain(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
