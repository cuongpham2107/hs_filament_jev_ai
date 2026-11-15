<?php

namespace App\Ai\HsLookup;

use App\Models\TariffYear;

/** Các dòng "Căn cứ phân loại" hiện trong khung chat cho mỗi bước của HsLookup. */
class LookupReasoning
{
    public function __construct(private ?TariffYear $year) {}

    /** "87 (84%), 73 (7%)" -> mỗi chương một dòng kèm tên chương. */
    public function chapters(string $chosen, int $candidates): string
    {
        preg_match_all('/(\d{2}) \((\d+%)\)/', $chosen, $matches, PREG_SET_ORDER);
        $names = $this->year?->chapters()->whereIn('no', array_column($matches, 1))->pluck('name', 'no') ?? collect();
        $lines = array_map(fn (array $m): string => "- Chương {$m[1]} ({$m[2]}): ".($names[$m[1]] ?? '?'), $matches);

        return "Chương Jev chọn ({$candidates} nhóm ứng viên):\n".implode("\n", $lines ?: [$chosen]);
    }

    /** @param  list<string>  $codes  nhóm 4 số theo thứ tự xác suất */
    public function headings(array $codes): string
    {
        $names = $this->year?->headings()->whereIn('code', $codes)->pluck('name', 'code') ?? collect();
        $lines = array_map(fn (string $c): string => '- '.substr($c, 0, 2).'.'.substr($c, 2).': '.($names[$c] ?? '?'), $codes);

        return "Nhóm đưa vào đi cây:\n".implode("\n", $lines);
    }

    /** @param  array{cac_buoc: string, do_tin_cay: float|int|string, trang_thai: string, can_lam_ro?: string}  $result */
    public function conclusion(array $result): string
    {
        return "Đường đi: {$result['cac_buoc']}\nĐộ tin cậy: ".round(((float) $result['do_tin_cay']) * 100, 1).'%'
            .($result['trang_thai'] === 'ok' ? ' — đủ để kết luận.' : " — chưa đủ, cần làm rõ: {$result['can_lam_ro']}");
    }
}
