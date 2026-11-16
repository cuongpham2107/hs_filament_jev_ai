<?php

namespace App\Ai\HsLookup;

/**
 * Đọc câu trả lời lượt đi cây thành mã 8 số, chuyển 1:1 từ scripts/wf_code_d.py:
 * P(mã) = P(nhóm) × tích P(nhánh | nút cha) dọc đường đi; trả mã ('ok') khi P(mã) >= 0.55,
 * hơn mã thứ hai >= 0.20 và noul 'có nhóm phù hợp' >= 0.3. Còn lại 'can_bo_sung' kèm điểm cần làm rõ.
 */
class LookupResult
{
    private const MIN_CONF = 0.55;

    private const MIN_MARGIN = 0.20;

    private const SURE = 0.7;

    private const NO_FIT = 0.3;

    /**
     * @param  array<string, array<string, mixed>>  $answers
     * @param  list<array<string, mixed>>  $trees
     * @return array{trang_thai: string, ma_hs: string, duong_dan: string, do_tin_cay: float, de_nham: string,
     *     de_nham_list: list<array{0: string, 1: float}>, ranked: list<array{0: string, 1: float}>, can_lam_ro: string,
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
        [$best, $joint] = $ranked[0] ?? ['', 0.0];
        $margin = $joint - ($ranked[1][1] ?? 0.0);
        $fits = $answers['co_nhom_phu_hop']['noul'] ?? null;
        $noHead = $fits !== null ? $fits < self::NO_FIT : $this->otherWins($pHead);
        [$g, $trail] = $steps[$best] ?? ['', []];
        $ok = $best !== '' && ! $noHead && $joint >= self::MIN_CONF && $margin >= self::MIN_MARGIN;

        $paths = [];
        foreach ($docs as $code => $tree) {
            foreach ($tree['codes'] as $c => $line) {
                $paths[(string) $c] = 'Nhóm '.substr((string) $code, 0, 2).'.'.substr((string) $code, 2).' > '.$line['path'];
            }
        }
        $runnersUp = array_slice($ranked, 1, 3);
        $clarify = $this->clarify($trail, $docs, $g, $pHead, $answers);

        return [
            'trang_thai' => $ok ? 'ok' : 'can_bo_sung',
            'ma_hs' => $noHead ? LookupQuestions::OTHER : $best,
            'duong_dan' => $paths[$best] ?? '',
            'do_tin_cay' => round($joint, 3),
            'de_nham' => implode("\n", array_map(fn (array $r): string => "{$r[0]} (".self::percent($r[1]).'): '.($paths[$r[0]] ?? ''), $runnersUp)),
            'de_nham_list' => $runnersUp,
            'ranked' => array_slice($ranked, 0, 10),
            'can_lam_ro' => count($clarify) > 1 ? 'Hàng hóa thuộc trường hợp nào: '.implode('; ', $clarify)
                : 'Mô tả thêm chất liệu, công dụng, cấu tạo, quy cách của hàng hóa',
            'cac_buoc' => implode(' › ', array_map(fn (array $s): string => "{$s[0]} (".self::percent($s[1]).')', $trail)),
            'chart_echarts' => self::chart($ranked),
            'paths' => $paths,
        ];
    }

    /** Điểm cần làm rõ = các nhánh ở bước đầu tiên dưới 0.7 trên đường đi của mã đứng đầu. */
    private function clarify(array $trail, array $docs, string $g, array $pHead, array $answers): array
    {
        foreach ($trail as [$name, $p, $k]) {
            if ($p >= self::SURE) {
                continue;
            }
            if ($k === null) {
                $heads = [];
                foreach (self::sorted($pHead) as [$c, $v]) {
                    if (isset($docs[$c]) && $v >= 0.1) {
                        $heads[] = 'Nhóm '.substr($c, 0, 2).'.'.substr($c, 2)." ({$docs[$c]['name']})";
                    }
                }

                return $heads;
            }
            $nodes = $docs[$g]['nodes'];
            $parent = $nodes[$k]['parent'] ?? 'r';
            $options = [];
            foreach (self::sorted($answers["{$g}_{$parent}"]['probabilities'] ?? []) as [$i, $v]) {
                if ($v >= 0.1 && ctype_digit($i)) {
                    $options[] = LookupQuestions::short($nodes[(int) $i]['label']);
                }
            }
            if (count($options) < 2) {  // Jev báo thiếu thông tin: liệt kê mọi nhánh ở cấp này để người dùng chọn
                $options = [];
                foreach ($nodes as $node) {
                    if (($node['parent'] ?? 'r') === $parent && count($options) < 8) {
                        $options[] = LookupQuestions::short($node['label']);
                    }
                }
            }

            return $options;
        }

        return [];
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
