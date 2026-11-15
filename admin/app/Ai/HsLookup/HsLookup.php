<?php

namespace App\Ai\HsLookup;

use App\Ai\Agents\HsRationaleAgent;
use App\Models\HsHeading;
use App\Models\HsNode;
use App\Models\TariffYear;
use App\Models\TraCuuLog;
use App\Services\TariffDocuments;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tra cứu mã HS trong Laravel (`App\Ai\HsLookup`): gọi Jev qua `laravel/ai`, đọc cây và thuế thẳng từ CSDL.
 * Phát các sự kiện SSE cho khung chat và lưu lịch sử:
 * conversation, step (bước Plan), reasoning (căn cứ), result (thẻ markdown, mã, độ tin cậy).
 */
class HsLookup
{
    /** @var array<string, string> các bước Plan hiển thị trên khung chat */
    public const STEPS = [
        'chuong' => 'Chọn chương',
        'nhom' => 'Chọn nhóm 4 số',
        'cay' => 'Lấy cây biểu thuế của các nhóm',
        'di_cay' => 'Chốt nhóm và đi cây từng cấp (Quy tắc 1, 6)',
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
    public function run(string $message, ?string $conversationId, callable $emit): void
    {
        $year = TariffYear::current() ?? throw new RuntimeException('Chưa có năm biểu thuế nào sẵn sàng.');
        $reasoning = new LookupReasoning($year);
        $description = $this->withEarlierTurns($message, $conversationId);
        $emit(['type' => 'conversation', 'id' => $conversationId ?? (string) Str::uuid()]);
        $step = $this->stepper($emit);

        $catalog = self::plain($this->documents->catalog($year));
        $chapters = $step('chuong', fn (): array => $this->jev->ask($description, $this->questions->chapter($catalog)));

        $candidates = $this->questions->headings($chapters['chuong'] ?? [], $catalog);
        $emit(['type' => 'reasoning', 'text' => $reasoning->chapters(
            implode(', ', array_map(fn (array $c): string => "{$c[0]} (".LookupResult::percent($c[1]).')', $candidates['chosen'])),
            $candidates['candidates'])]);
        $picked = $step('nhom', function () use ($description, $candidates): array {
            return $this->questions->pickHeadings($this->jev->ask($description, $candidates['questions'])['nhom'] ?? []);
        });
        $emit(['type' => 'reasoning', 'text' => $reasoning->headings($picked)]);

        $trees = $step('cay', fn (): array => HsHeading::query()->whereBelongsTo($year)->whereIn('code', $picked)->get()
            ->sortBy(fn (HsHeading $h): int => array_search($h->code, $picked, true))
            ->map(fn (HsHeading $h): array => self::plain($this->documents->heading($h)))->values()->all());

        $asked = $this->questions->tree($trees, $description, $this->documents->rules($year));
        $answers = $step('di_cay', fn (): array => $this->jev->ask($asked['state'], $asked['questions']));
        $trees = array_values(array_filter($trees, fn (array $t): bool => ! in_array((string) $t['code'], $asked['skipped'], true)));

        $read = $step('ket_luan', fn (): array => $this->result->read($answers, $trees));
        $emit(['type' => 'reasoning', 'text' => $reasoning->conclusion($read)]);

        $markdown = $step('the', function () use ($read, $trees, $year, $description): string {
            if ($read['trang_thai'] !== 'ok') {
                return $this->card->needsMoreInfo($read, $description);
            }
            $line = HsNode::query()->whereBelongsTo($year)->where('code', $read['ma_hs'])->first();
            $rationale = $this->rationale->explain($description, $read, $trees);

            return $this->card->ok($read, $line ? self::plain($this->documents->tax($line)) : [], $description, $year->year, $rationale);
        });
        $emit(['type' => 'result', 'status' => $read['trang_thai'], 'markdown' => $markdown,
            'code' => strlen($read['ma_hs']) === 8 ? $read['ma_hs'] : null, 'confidence' => $read['do_tin_cay']]);
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

    /** Mảng/đối tượng lồng nhau (TariffDocuments trả object cho map rỗng) -> mảng thuần. */
    private static function plain(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
