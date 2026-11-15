<?php

namespace App\Services;

use App\Models\TariffYear;

/**
 * Gom sự kiện stream của chatflow tra cứu HS trên Dify (API /chat-messages) thành sự kiện cho khung chat:
 *  - conversation: {id}                            -> lượt sau trả lời bổ sung trong cùng hội thoại
 *  - step:      {id, status: running|done|error}   -> Plan (các bước biết trước)
 *  - reasoning: {text}                             -> Reasoning (căn cứ Jev đưa ra ở từng bước)
 *  - result:    {status: ok|can_bo_sung, markdown} -> thẻ kết quả / thẻ cần bổ sung
 *  - error:     {message}
 * Trình duyệt chỉ nhận những sự kiện này, không thấy dữ liệu thô của node hay API key.
 * Thẻ lấy từ đầu ra node dựng thẻ (template_card / template_more), không lấy chữ của các node Answer vì Answer
 * 'Tiến trình' đã được Plan / Reasoning thể hiện. Mỗi lần chạy dùng một đối tượng mới (có trạng thái).
 */
class DifyRunEvents
{
    /** @var array<string, array{label: string, nodes: list<string>}> theo thứ tự chạy */
    public const STEPS = [
        'chuong' => ['label' => 'Chọn chương', 'nodes' => ['ghep_mo_ta', 'http_mucluc', 'code_chuong', 'eval_chuong']],
        'nhom' => ['label' => 'Chọn nhóm 4 số', 'nodes' => ['code_a', 'eval_nhom', 'code_b', 'tien_do']],
        'cay' => ['label' => 'Lấy cây biểu thuế của các nhóm', 'nodes' => ['iter', 'iterstart', 'http_tree']],
        'di_cay' => ['label' => 'Chốt nhóm và đi cây từng cấp (Quy tắc 1, 6)', 'nodes' => ['code_sub', 'eval_sub']],
        'ket_luan' => ['label' => 'Kết luận mã', 'nodes' => ['code_d', 'ifelse']],
        'the' => ['label' => 'Tra thuế và dựng thẻ kết quả', 'nodes' => ['http_tax', 'code_e', 'template_card', 'template_more', 'nho_ok', 'nho_more', 'answer_ok', 'answer_more']],
    ];

    private ?string $current = null;

    private ?string $conversationId = null;

    private bool $hasResult = false;

    /** Năm biểu thuế dùng để tra tên chương / nhóm cho phần căn cứ (mặc định: năm đang dùng). */
    public function __construct(private ?TariffYear $year = null)
    {
        $this->year ??= TariffYear::current();
    }

    /**
     * @param  array<string, mixed>  $event  một sự kiện "data: {...}" của API /chat-messages (streaming)
     * @return list<array<string, mixed>>
     */
    public function map(array $event): array
    {
        $data = $event['data'] ?? [];
        $conversation = [];
        if ($this->conversationId === null && ! empty($event['conversation_id'])) {
            $this->conversationId = (string) $event['conversation_id'];
            $conversation = [['type' => 'conversation', 'id' => $this->conversationId]];
        }

        return [...$conversation, ...match ($event['event'] ?? null) {
            'node_started', 'iteration_started' => $this->enter($this->stepOf($data['node_id'] ?? '')),
            'node_finished' => $this->finished($data),
            'workflow_finished' => $this->workflowFinished($data),
            'error' => [['type' => 'error', 'message' => (string) ($event['message'] ?? 'Dify báo lỗi')]],
            default => [],
        }];
    }

    private function stepOf(string $nodeId): ?string
    {
        foreach (self::STEPS as $id => $step) {
            if (in_array($nodeId, $step['nodes'], true)) {
                return $id;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function enter(?string $step): array
    {
        if ($step === null || $step === $this->current) {
            return [];
        }
        $events = $this->current === null ? [] : [['type' => 'step', 'id' => $this->current, 'status' => 'done']];
        $this->current = $step;

        return [...$events, ['type' => 'step', 'id' => $step, 'status' => 'running']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function finished(array $data): array
    {
        if (($data['status'] ?? 'succeeded') === 'failed') {
            $step = $this->stepOf($data['node_id'] ?? '') ?? $this->current;

            return [['type' => 'step', 'id' => $step, 'status' => 'error'],
                ['type' => 'error', 'message' => 'Bước "'.($data['title'] ?? '').'" lỗi: '.mb_substr((string) ($data['error'] ?? ''), 0, 300)]];
        }
        $card = match ($data['node_id'] ?? '') {
            'template_card' => 'ok',
            'template_more' => 'can_bo_sung',
            default => null,
        };
        if ($card !== null) {
            $this->hasResult = true;

            return [['type' => 'result', 'status' => $card, 'markdown' => (string) ($data['outputs']['output'] ?? '')]];
        }
        $text = $this->reasoning($data['node_id'] ?? '', $data['outputs'] ?? []);

        return $text === null ? [] : [['type' => 'reasoning', 'text' => $text]];
    }

    /**
     * Căn cứ đọc được ở mỗi bước: chương, nhóm Jev xét, đường đi trong cây kèm xác suất.
     *
     * @param  array<string, mixed>  $out
     */
    private function reasoning(string $nodeId, array $out): ?string
    {
        return match ($nodeId) {
            'code_a' => isset($out['chuong']) ? $this->chapters((string) $out['chuong'], (int) ($out['so_ung_vien'] ?? 0)) : null,
            'code_b' => isset($out['nhom']) ? $this->headings((array) $out['nhom']) : null,
            'code_d' => isset($out['cac_buoc']) ? "Đường đi: {$out['cac_buoc']}\nĐộ tin cậy: "
                .round(((float) ($out['do_tin_cay'] ?? 0)) * 100, 1).'%'
                .(($out['trang_thai'] ?? '') === 'ok' ? ' — đủ để kết luận.' : " — chưa đủ, cần làm rõ: {$out['can_lam_ro']}") : null,
            default => null,
        };
    }

    /** "87 (84%), 73 (7%)" -> mỗi chương một dòng kèm tên chương. */
    private function chapters(string $chosen, int $candidates): string
    {
        preg_match_all('/(\d{2}) \((\d+%)\)/', $chosen, $matches, PREG_SET_ORDER);
        $names = $this->year?->chapters()->whereIn('no', array_column($matches, 1))->pluck('name', 'no') ?? collect();
        $lines = array_map(fn (array $m): string => "- Chương {$m[1]} ({$m[2]}): ".($names[$m[1]] ?? '?'), $matches);

        return "Chương Jev chọn ({$candidates} nhóm ứng viên):\n".implode("\n", $lines ?: [$chosen]);
    }

    /** @param  list<string>  $codes  nhóm 4 số theo thứ tự xác suất */
    private function headings(array $codes): string
    {
        $names = $this->year?->headings()->whereIn('code', $codes)->pluck('name', 'code') ?? collect();
        $lines = array_map(fn (string $c): string => '- '.substr($c, 0, 2).'.'.substr($c, 2).': '.($names[$c] ?? '?'), $codes);

        return "Nhóm đưa vào đi cây:\n".implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function workflowFinished(array $data): array
    {
        $events = $this->current === null ? [] : [['type' => 'step', 'id' => $this->current, 'status' => 'done']];
        if (($data['status'] ?? '') !== 'succeeded' || ! $this->hasResult) {
            return [...$events, ['type' => 'error', 'message' => (string) ($data['error'] ?? 'Workflow không trả về thẻ kết quả')]];
        }

        return $events;
    }
}
