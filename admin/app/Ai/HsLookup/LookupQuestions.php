<?php

namespace App\Ai\HsLookup;

/**
 * Dựng câu hỏi cho từng lượt Jev, chuyển 1:1 từ các node code của workflow Dify
 * (scripts/wf_code_chuong.py, wf_code_a.py, node 'Chọn 2–5 nhóm', wf_code_sub.py). Giữ nguyên câu chữ và ngưỡng để
 * so được kết quả với bản Dify. Khoá mảng PHP kiểu "87", "8714" bị đổi thành số nguyên: luôn ép (string) khi đọc.
 */
class LookupQuestions
{
    public const OTHER = 'KHONG_PHU_HOP';

    public const UNKNOWN = 'CHUA_DU_THONG_TIN';

    private const UNKNOWN_TEXT = 'Mô tả hàng hóa không đủ thông tin để phân biệt các nhánh này: các nhánh khác nhau ở một tiêu chí '
        .'(kích thước, định lượng, thành phần, công dụng, tình trạng...) mà mô tả không nói tới';

    /** Ứng viên nhóm: lấy chương theo xác suất tới khi cộng dồn >= 0.9, tối đa 3 chương, bỏ chương < 0.05; tối đa 250 nhóm. */
    private const CHAPTER_MASS = 0.9;

    private const MAX_CHAPTERS = 3;

    private const MIN_CHAPTER_P = 0.05;

    private const MAX_OPTIONS = 250;

    /**
     * Ký tự câu hỏi tối đa mỗi lượt Jev đi cây. Bản Dify đo 160.000 theo json.dumps của Python (có dấu cách sau ':' và
     * ','); ở đây đo JSON gọn của PHP nên hạ xuống 155.000 cho tương đương (Jev giới hạn 64k token: 146k chạy, 181k lỗi).
     */
    private const BUDGET = 155_000;

    /** Độ dài phần 'GỒM CÁC DÒNG' của mỗi nhóm trong câu chọn nhóm. */
    private const CONTENTS_CHARS = 1500;

    private const GUIDE = [
        'Chỉ so sánh các nhánh cùng cấp với nhau (Quy tắc 6).',
        "Nhánh có phần 'KHÔNG PHẢI: ...' (các dòng 'Loại khác') chỉ đúng khi mô tả cho thấy rõ hàng không thuộc các nhánh được liệt kê trong đó.",
        'Nếu mô tả thiếu thông tin mà các nhánh dùng để phân biệt, chọn '.self::UNKNOWN.' thay vì đoán.',
    ];

    private const NOT_FOR = '/^(.*) \(không phải: (.*)\)$/u';

    /**
     * Bước 1: Jev chọn chương trên mục lục (không có lựa chọn "không chương nào": hàng nào cũng thuộc một chương).
     *
     * @param  array{chapters: array<string, array{name: string, section: string, section_name: string}>}  $catalog
     * @return array<string, array<string, mixed>>
     */
    public function chapter(array $catalog): array
    {
        $criteria = [];
        foreach ($catalog['chapters'] as $no => $chapter) {
            $no = (string) $no;
            $criteria[$no] = "Chương {$no}: {$chapter['name']} (Phần {$chapter['section']}: {$chapter['section_name']})";
        }

        return ['chuong' => ['type' => 'choice',
            'instructions' => 'Chọn chương của Biểu thuế XNK Việt Nam chứa hàng hóa được mô tả. Tên Phần, Chương '
                .'chỉ để tra cứu (Quy tắc 1): xét theo bản chất hàng hóa (vật liệu, công dụng, chức '
                .'năng; là bộ phận của máy hoặc xe nào) chứ không theo việc trùng chữ.',
            'criteria' => $criteria]];
    }

    /**
     * Bước 2: ứng viên nhóm = mọi nhóm của 1–3 chương Jev chọn.
     *
     * @param  array<string, mixed>  $chapterAnswer  câu trả lời câu 'chuong'
     * @param  array{headings: array<string, list<array{0: string, 1: string}>>}  $catalog
     * @return array{questions: array<string, array<string, mixed>>, chosen: list<array{0: string, 1: float}>, candidates: int}
     */
    public function headings(array $chapterAnswer, array $catalog): array
    {
        $probabilities = self::sortedProbabilities($chapterAnswer, exclude: self::OTHER);
        $chosen = [];
        $mass = 0.0;
        foreach ($probabilities as [$no, $p]) {
            if ($p < self::MIN_CHAPTER_P || count($chosen) >= self::MAX_CHAPTERS || $mass >= self::CHAPTER_MASS) {
                break;
            }
            $chosen[] = [$no, $p];
            $mass += $p;
        }

        $criteria = [];
        foreach ($chosen as [$no]) {
            foreach ($catalog['headings'][$no] ?? [] as [$code, $name]) {
                if (count($criteria) < self::MAX_OPTIONS) {
                    $criteria[(string) $code] ??= $name;
                }
            }
        }
        $candidates = count($criteria);
        $criteria[self::OTHER] = 'Không nhóm nào trong danh sách bao trùm hàng hóa này';

        return [
            'questions' => ['nhom' => ['type' => 'choice',
                'instructions' => 'Chọn nhóm 4 số trong Biểu thuế XNK Việt Nam bao trùm đúng hàng hóa được mô tả, '
                    .'theo nội dung nhóm và chú giải (Quy tắc 1). Khi hàng có thể vào nhiều nhóm, nhóm mô '
                    .'tả cụ thể nhất được ưu tiên (Quy tắc 3a).',
                'criteria' => $criteria]],
            'chosen' => $chosen,
            'candidates' => $candidates,
        ];
    }

    /**
     * Bước 3: lấy nhóm p >= 0.02 theo thứ tự xác suất, dừng khi cộng dồn (tính cả KHONG_PHU_HOP) >= 0.95 hoặc đủ 5;
     * Jev nghiêng hẳn về KHONG_PHU_HOP thì vẫn thử 2 nhóm đầu.
     *
     * @param  array<string, mixed>  $headingAnswer
     * @return list<string>
     */
    public function pickHeadings(array $headingAnswer): array
    {
        $chosen = [];
        $total = 0.0;
        $sorted = self::sortedProbabilities($headingAnswer);
        foreach ($sorted as [$code, $p]) {
            $total += $p;
            if ($code !== self::OTHER && $p >= 0.02) {
                $chosen[] = $code;
            }
            if (count($chosen) >= 5 || $total >= 0.95) {
                break;
            }
        }

        return $chosen ?: array_slice(array_values(array_filter(
            array_column($sorted, 0), fn (string $c): bool => $c !== self::OTHER)), 0, 2);
    }

    /**
     * Bước 4: một lượt Jev chốt nhóm (Quy tắc 1) + noul 'có nhóm phù hợp' + mỗi nút có >= 2 nhánh con một câu chọn giữa
     * các nhánh cùng cấp (Quy tắc 6). Nhóm làm vượt BUDGET thì bỏ (nhóm ít khả năng nhất trước).
     *
     * @param  list<array<string, mixed>>  $trees  cây nhóm (TariffDocuments::heading), theo thứ tự xác suất
     * @return array{questions: array<string, array<string, mixed>>, state: array<string, mixed>, skipped: list<string>}
     */
    public function tree(array $trees, string $message, string $rules): array
    {
        $heads = $names = $notes = $questions = [];
        $skipped = [];
        foreach ($trees as $tree) {
            $code = (string) $tree['code'];
            $group = 'Nhóm '.substr($code, 0, 2).'.'.substr($code, 2);
            $own = $this->nodeQuestions($tree, $group);
            if ($heads && self::length([...$questions, ...$own]) > self::BUDGET) {
                $skipped[] = $code;

                continue;
            }
            $questions = [...$questions, ...$own];
            $names[$code] = "{$group}: {$tree['name']} ({$tree['chapter']})";
            // tên nhóm thường chung chung: kèm các dòng bên trong để Jev thấy hàng được nêu đích danh ở nhóm nào
            $heads[$code] = "{$names[$code]} | GỒM CÁC DÒNG: ".self::contents($tree);
            foreach ($tree['notes'] as $title => $body) {
                $notes[(string) $title] ??= $body;
            }
        }

        return [
            'questions' => [
                'nhom' => ['type' => 'choice',
                    'instructions' => 'Chọn nhóm 4 số bao trùm đúng hàng hóa ở `mo_ta_hang_hoa`, xác định theo nội dung nhóm '
                        .'và `chu_giai` của Phần, Chương (Quy tắc 1); áp dụng Quy tắc 2 đến 5 khi cần.',
                    'criteria' => $heads],
                'co_nhom_phu_hop' => ['type' => 'noul',
                    'instructions' => 'Hàng hóa ở `mo_ta_hang_hoa` có thuộc một trong các nhóm ở '
                        .'`cac_nhom_dang_xet` không (theo nội dung nhóm và `chu_giai`)?'],
                ...$questions,
            ],
            'state' => ['mo_ta_hang_hoa' => $message, 'sau_quy_tac' => $rules, 'cach_chon_nhanh' => self::GUIDE,
                'cac_nhom_dang_xet' => array_values($names), 'chu_giai' => (object) $notes],
            'skipped' => $skipped,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function nodeQuestions(array $tree, string $group): array
    {
        $nodes = $tree['nodes'];
        $children = [];
        foreach ($nodes as $i => $node) {
            $children[$node['parent'] ?? 'r'][] = $i;
        }
        $out = [];
        foreach ($children as $parent => $kids) {
            if (count($kids) < 2) {
                continue;
            }
            $where = $parent === 'r' ? $group : "{$group} > ".implode(' > ', self::chain($nodes, (int) $parent));
            $criteria = [];
            foreach ($kids as $i) {
                $criteria[(string) $i] = self::option($nodes[$i]);
            }
            $criteria[self::UNKNOWN] = self::UNKNOWN_TEXT;
            $out["{$tree['code']}_{$parent}"] = ['type' => 'choice',
                'instructions' => "Giả sử hàng hóa ở `mo_ta_hang_hoa` thuộc «{$where}». Chọn nhánh con mô tả đúng hàng hóa.",
                'criteria' => $criteria];
        }

        return $out;
    }

    /** @return list<string> */
    private static function chain(array $nodes, ?int $i): array
    {
        $out = [];
        while ($i !== null) {
            array_unshift($out, self::short($nodes[$i]['label']));
            $i = $nodes[$i]['parent'];
        }

        return $out;
    }

    public static function short(string $label): string
    {
        return preg_match(self::NOT_FOR, $label, $m) ? $m[1] : $label;
    }

    /** Tên nhánh, phần 'KHÔNG PHẢI' của dòng 'Loại khác' tách riêng, kèm tiếng Anh và ghi chú. */
    private static function option(array $node): string
    {
        $parts = [preg_match(self::NOT_FOR, $node['label'], $m) ? $m[1] : $node['label']];
        if ($m) {
            $parts[] = "KHÔNG PHẢI: {$m[2]}";
        }
        if ($node['en']) {
            $parts[] = "EN: {$node['en']}";
        }
        if ($node['note']) {
            $parts[] = "Ghi chú: {$node['note']}";
        }

        return implode(' | ', $parts);
    }

    /** Các dòng trong nhóm (bỏ 'Loại khác'), không trùng, cắt ở CONTENTS_CHARS ký tự. */
    private static function contents(array $tree): string
    {
        $seen = [];
        foreach ($tree['nodes'] as $node) {
            $label = trim(self::short($node['label']));
            if ($label !== '' && ! str_starts_with(mb_strtolower($label), 'loại khác') && ! in_array($label, $seen, true)) {
                $seen[] = $label;
            }
        }
        $text = implode('; ', $seen);
        if (mb_strlen($text) <= self::CONTENTS_CHARS) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::CONTENTS_CHARS);

        return mb_substr($cut, 0, (int) mb_strrpos($cut, '; ')).'; ...';
    }

    private static function length(array $questions): int
    {
        return mb_strlen(json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Xác suất các lựa chọn giảm dần (giữ thứ tự gốc khi bằng nhau như sorted() của Python), dạng cặp [lựa chọn, p]
     * để tên lựa chọn luôn là chuỗi (mảng có khoá thì PHP đổi "87" thành số nguyên).
     *
     * @return list<array{0: string, 1: float}>
     */
    public static function sortedProbabilities(array $answer, ?string $exclude = null): array
    {
        $probabilities = [];
        foreach ($answer['probabilities'] ?? [] as $option => $p) {
            if ((string) $option !== $exclude) {
                $probabilities[] = [(string) $option, (float) $p];
            }
        }
        usort($probabilities, fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $probabilities;
    }
}
