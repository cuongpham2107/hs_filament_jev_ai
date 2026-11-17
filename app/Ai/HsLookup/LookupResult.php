<?php

namespace App\Ai\HsLookup;

/**
 * Đọc câu trả lời lượt đi cây thành mã 8 số.
 *
 * Chốt mã ('ok') theo **nút yếu nhất** trên đường đi, không theo tích xác suất: cần P(nhóm) và mọi nút rẽ nhánh
 * đều >= 0.60, nút yếu nhất cách nhánh thứ hai >= 0.20, và noul `co_nhom_phu_hop` >= 0.7 (dải 0.3–0.7 là "phân vân",
 * phải hỏi lại). Tích xác suất vẫn được tính để xếp hạng các mã ứng viên, nhưng không dùng làm ngưỡng — 4 tầng 85%
 * cho tích 0,52 sẽ bị chặn oan. Còn lại 'can_bo_sung' kèm câu hỏi làm rõ sinh từ chính các nhánh của biểu thuế.
 */
class LookupResult
{
    private const WEAKEST_MIN = 0.60;

    private const MIN_MARGIN = 0.20;

    private const SURE = 0.7;

    private const NO_FIT = 0.3;

    private const YES_FIT = 0.7;

    /** Số mã ứng viên trả cho người dùng chọn (mã đầu + 3 mã kế). */
    private const MAX_CANDIDATES = 4;

    /** Số câu hỏi làm rõ tối đa mỗi lượt. */
    private const MAX_QUESTIONS = 2;

    /** Số nhãn nhánh đưa vào LookupQuestions::ask (hàm tự chọn nhóm đông nhất + 1 nhánh khác). */
    private const MAX_ASK_LABELS = 8;

    /**
     * @param  array<string, array<string, mixed>>  $answers
     * @param  list<array<string, mixed>>  $trees
     * @return array{trang_thai: string, ma_hs: string, duong_dan: string, do_tin_cay: float, de_nham: string,
     *     de_nham_list: list<array{0: string, 1: float}>, candidates: list<array{0: string, 1: float}>,
     *     ranked: list<array{0: string, 1: float}>, can_lam_ro: string, goi_y: list<string>,
     *     cac_buoc: string, chart_echarts: string, paths: array<string, string>}
     */
    public function read(array $answers, array $trees): array
    {
        $pHead = $answers['nhom']['probabilities'] ?? [];
        $codes = $steps = $docs = [];
        foreach ($trees as $tree) {
            $g = (string) $tree['code'];
            $nodes = $tree['nodes'];
            $docs[$g] = $tree;
            $kids = [];
            foreach ($nodes as $i => $node) {
                $kids[$node['parent'] ?? 'r'][] = $i;
            }
            // P(nút i | nút cha); nút không có anh em thì chắc chắn 1
            $cond = function (int $i) use ($nodes, $kids, $answers, $g): float {
                $parent = $nodes[$i]['parent'] ?? 'r';
                if (count($kids[$parent]) < 2) {
                    return 1.0;
                }

                return (float) ($answers["{$g}_{$parent}"]['probabilities'][(string) $i] ?? 0.0);
            };
            foreach ($nodes as $i => $node) {
                if (strlen((string) $node['code']) !== 8) {
                    continue;
                }
                $chain = [];
                for ($j = $i; $j !== null; $j = $nodes[$j]['parent']) {
                    array_unshift($chain, $j);
                }
                $p = (float) ($pHead[$g] ?? 0.0);
                $trail = [['Nhóm '.substr($g, 0, 2).'.'.substr($g, 2), $p, null]];
                foreach ($chain as $k) {
                    $p *= $cond($k);
                    $trail[] = [LookupQuestions::short($nodes[$k]['label']), $cond($k), $k];
                }
                $codes[(string) $node['code']] = $p;
                $steps[(string) $node['code']] = [$g, $trail];
            }
        }

        $ranked = [];
        foreach ($codes as $code => $p) {
            $ranked[] = [(string) $code, $p];
        }
        usort($ranked, fn (array $a, array $b): int => $b[1] <=> $a[1]);
        [$best] = $ranked[0] ?? [''];
        $fits = $answers['co_nhom_phu_hop']['noul'] ?? null;
        // Noul ba dải (https://docs.typesafe.ai/patterns/companion-noul): rõ không khớp / phân vân / rõ khớp.
        $noFit = $fits !== null ? $fits < self::NO_FIT : $this->otherWins($pHead);
        $unsureFit = $fits !== null && $fits >= self::NO_FIT && $fits < self::YES_FIT;
        [$g, $trail] = $steps[$best] ?? ['', []];
        [$weakest, $weakestMargin] = $this->weakest($trail, $g, $docs, $answers);
        $ok = $best !== '' && ! $noFit && ! $unsureFit
            && $weakest >= self::WEAKEST_MIN && $weakestMargin >= self::MIN_MARGIN;

        $paths = [];
        foreach ($docs as $code => $tree) {
            foreach ($tree['codes'] as $c => $line) {
                $paths[(string) $c] = 'Nhóm '.substr((string) $code, 0, 2).'.'.substr((string) $code, 2).' > '.$line['path'];
            }
        }
        $runnersUp = array_slice($ranked, 1, 3);
        $clarify = $this->clarify($trail, $docs, $g, $pHead, $answers);
        if ($unsureFit) {
            // Noul ở dải giữa: chưa rõ hàng có thuộc nhóm nào đã chọn, nên hỏi lại đúng chuyện chọn nhóm.
            $heading = $this->headingQuestion($pHead, $docs);
            if ($heading['question'] !== '' && ! in_array($heading['question'], array_column($clarify, 'question'), true)) {
                $clarify = array_slice([$heading, ...$clarify], 0, self::MAX_QUESTIONS);
            }
        }

        return [
            'trang_thai' => $ok ? 'ok' : 'can_bo_sung',
            'ma_hs' => $noFit ? LookupQuestions::OTHER : $best,
            'duong_dan' => $paths[$best] ?? '',
            'do_tin_cay' => round($weakest, 3),
            'de_nham' => implode("\n", array_map(fn (array $r): string => "{$r[0]} (".self::percent($r[1]).'): '.($paths[$r[0]] ?? ''), $runnersUp)),
            'de_nham_list' => $runnersUp,
            'candidates' => array_slice($ranked, 0, self::MAX_CANDIDATES),
            'ranked' => array_slice($ranked, 0, 10),
            'can_lam_ro' => $clarify !== [] ? implode("\n", array_column($clarify, 'question'))
                : 'Mô tả thêm chất liệu, công dụng và cấu tạo của hàng hóa',
            'goi_y' => array_slice($clarify[0]['choices'] ?? [], 0, self::MAX_CANDIDATES),
            'cac_buoc' => implode(' › ', array_map(fn (array $s): string => "{$s[0]} (".self::percent($s[1]).')', $trail)),
            'chart_echarts' => self::chart($ranked),
            'paths' => $paths,
        ];
    }

    /**
     * Nút yếu nhất trên đường đi của mã: xác suất nhỏ nhất và cách biệt với nhánh mạnh nhất còn lại tại nút đó.
     * Nút không có anh em (chắc chắn 1.0) không tính.
     *
     * @param  list<array{0: string, 1: float, 2: ?int}>  $trail
     * @param  array<string, array<string, mixed>>  $docs
     * @param  array<string, array<string, mixed>>  $answers
     * @return array{0: float, 1: float} [xác suất nút yếu nhất, cách biệt tại nút đó]
     */
    private function weakest(array $trail, string $g, array $docs, array $answers): array
    {
        $weakest = 1.0;
        $weakestMargin = 1.0;
        $nodes = $docs[$g]['nodes'] ?? [];
        foreach ($trail as $index => [$name, $value, $k]) {
            if ($value >= 1.0) {
                continue;
            }
            if ($index === 0) {
                $probabilities = (array) ($answers['nhom']['probabilities'] ?? []);
                $key = $g;
            } else {
                $parent = $nodes[$k]['parent'] ?? 'r';
                $probabilities = (array) ($answers["{$g}_{$parent}"]['probabilities'] ?? []);
                $key = (string) $k;
            }
            unset($probabilities[$key]);
            $second = $probabilities === [] ? 0.0 : max(array_map('floatval', $probabilities));
            if ($value < $weakest) {
                $weakest = $value;
                $weakestMargin = $value - $second;
            }
        }

        return [$weakest, $weakestMargin];
    }

    /**
     * Câu hỏi làm rõ cho các nhánh chưa chắc trên đường đi của mã đứng đầu (tối đa 2 câu).
     * Câu chữ và lựa chọn lấy từ LookupQuestions::askWithChoices, tức là từ chính các nhánh của biểu thuế.
     *
     * @return list<array{question: string, choices: list<string>}>
     */
    private function clarify(array $trail, array $docs, string $g, array $pHead, array $answers): array
    {
        $questions = [];
        foreach ($trail as [$name, $p, $k]) {
            if (count($questions) >= self::MAX_QUESTIONS) {
                break;
            }
            if ($p >= self::SURE) {
                continue;
            }
            if ($k === null) {
                $question = $this->headingQuestion($pHead, $docs);
                if ($question['question'] !== '') {
                    $questions[] = $question;
                }

                continue;
            }
            $nodes = $docs[$g]['nodes'];
            $parent = $nodes[$k]['parent'] ?? 'r';
            $labels = [];
            foreach (self::sorted($answers["{$g}_{$parent}"]['probabilities'] ?? []) as [$i, $v]) {
                if (! ctype_digit($i) || count($labels) >= self::MAX_ASK_LABELS) {
                    continue;
                }
                $labels[] = LookupQuestions::short($nodes[(int) $i]['label']);
            }
            if (count($labels) < 2) {  // Jev dồn hết vào 'chưa đủ thông tin': lấy các nhánh đầu của cấp này
                $labels = [];
                foreach ($nodes as $node) {
                    if (($node['parent'] ?? 'r') === $parent && count($labels) < self::MAX_ASK_LABELS) {
                        $labels[] = LookupQuestions::short($node['label']);
                    }
                }
            }
            $question = LookupQuestions::askWithChoices($labels);
            if ($question['question'] !== '') {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * Câu hỏi chọn nhóm khi Jev chia xác suất giữa nhiều nhóm (hoặc noul ở dải giữa). Rỗng nếu chỉ có một nhóm.
     *
     * @param  array<string, float>  $pHead
     * @param  array<string, array<string, mixed>>  $docs
     * @return array{question: string, choices: list<string>}
     */
    private function headingQuestion(array $pHead, array $docs): array
    {
        $heads = [];
        foreach (self::sorted($pHead) as [$code, $p]) {
            if (! preg_match('/^\d{4}$/', $code) || count($heads) >= self::MAX_CANDIDATES) {
                continue;
            }
            $name = isset($docs[$code]) ? ' ('.$docs[$code]['name'].')' : '';
            $heads[] = 'Nhóm '.substr($code, 0, 2).'.'.substr($code, 2).$name;
        }

        return count($heads) > 1
            ? ['question' => 'Hàng thuộc nhóm nào: '.implode('; ', $heads).'?', 'choices' => $heads]
            : ['question' => '', 'choices' => []];
    }

    private function otherWins(array $pHead): bool
    {
        $others = array_filter($pHead, fn ($k): bool => (string) $k !== LookupQuestions::OTHER, ARRAY_FILTER_USE_KEY);

        return ($pHead[LookupQuestions::OTHER] ?? 0.0) >= max([0.0, ...array_values($others)]);
    }

    /** @return list<array{0: string, 1: float}> */
    private static function sorted(array $probabilities): array
    {
        return LookupQuestions::sortedProbabilities(['probabilities' => $probabilities]);
    }

    /** Như f"{p:.0%}" của Python. */
    public static function percent(float $p): string
    {
        return round($p * 100).'%';
    }

    /** Option ECharts: mã đứng đầu + 3 mã kế tiếp + phần còn lại. */
    private static function chart(array $ranked): string
    {
        $data = [];
        foreach (array_slice($ranked, 0, 4) as [$code, $p]) {
            if ($p >= 0.005) {
                $data[] = ['name' => substr($code, 0, 4).'.'.substr($code, 4, 2).'.'.substr($code, 6), 'value' => round($p * 100, 1)];
            }
        }
        $rest = round(100 - array_sum(array_column($data, 'value')), 1);
        if ($rest >= 0.5) {
            $data[] = ['name' => 'Khác / chưa rõ', 'value' => $rest, 'itemStyle' => ['color' => '#d0d5dd']];
        }

        return json_encode(['tooltip' => ['trigger' => 'item', 'formatter' => '{b}: {c}%'],
            'legend' => ['bottom' => 0, 'type' => 'scroll'],
            'series' => [['type' => 'pie', 'radius' => ['35%', '65%'], 'center' => ['50%', '45%'],
                'label' => ['formatter' => "{b}\n{c}%", 'fontSize' => 13], 'data' => $data]]],
            JSON_UNESCAPED_UNICODE);
    }
}
