<?php

namespace App\Services;

use App\Models\HsChapter;
use App\Models\HsHeading;
use App\Models\HsNode;
use App\Models\TariffYear;

/**
 * Dựng các tài liệu JSON mà workflow Dify đọc, từ các bảng cây. Định dạng giữ đúng như hs_tree/*.json
 * do hs_model.py sinh ra (test so khớp từng nhóm), nên workflow không phải đổi code.
 */
class TariffDocuments
{
    /**
     * Cây một nhóm cho Jev: chú giải theo Quy tắc 1 (nhóm, chương, phần) và các nút theo cấp cho Quy tắc 6.
     *
     * @return array{code: string, name: string, en: string, chapter: string, special: bool, notes: object, subheadings: object, codes: object, nodes: list<array<string, mixed>>}
     */
    public function heading(HsHeading $heading): array
    {
        $heading->loadMissing(['chapter.section', 'nodes']);
        $chapter = $heading->chapter;
        $section = $chapter->section;

        $notes = [];
        foreach ([
            "Ghi chú trong nhóm {$heading->code}" => $heading->notes,
            "Chú giải Chương {$chapter->no}: {$chapter->name}" => $chapter->notes,
            "Chú giải Phần {$section?->no}: {$section?->name}" => $section?->notes,
        ] as $title => $lines) {
            if ($lines) {
                $notes[$title] = implode("\n", $lines);
            }
        }

        $positions = $heading->nodes->pluck('position', 'id');
        $codes = [];
        foreach ($heading->nodes->whereNotNull('path') as $node) {
            $codes[$node->code] = ['sub' => $node->subheading, 'path' => $node->path, 'note' => $node->note, 'en' => $node->path_en];
        }

        return [
            'code' => $heading->code,
            'name' => $heading->name,
            'en' => $heading->en,
            'chapter' => "Chương {$chapter->no}: {$chapter->name}",
            'special' => $chapter->no === '98',
            'notes' => (object) $notes,
            'subheadings' => (object) $heading->subheadings,
            'codes' => (object) $codes,
            'nodes' => $heading->nodes->map(fn (HsNode $node): array => [
                'code' => $node->code,
                'label' => $node->label,
                'en' => $node->en,
                'note' => $node->note,
                'parent' => $node->parent_id === null ? null : $positions[$node->parent_id],
            ])->values()->all(),
        ];
    }

    /**
     * Mục lục chương -> nhóm cho bước Jev chọn chương (bỏ chương 98 và chương không có nhóm).
     *
     * @return array{chapters: object, headings: object, notes: object, loai_tru: object}
     */
    public function catalog(TariffYear $year): array
    {
        $chapters = HsChapter::query()->whereBelongsTo($year)->where('no', '!=', '98')->has('headings')
            ->with(['section', 'headings' => fn ($query) => $query->orderBy('id')])->orderBy('id')->get();

        return [
            'chapters' => (object) $chapters->mapWithKeys(fn (HsChapter $chapter): array => [$chapter->no => [
                'name' => $chapter->name,
                'section' => $chapter->section?->no ?? '',
                'section_name' => $chapter->section?->name ?? '',
            ]])->all(),
            'headings' => (object) $chapters->mapWithKeys(fn (HsChapter $chapter): array => [
                $chapter->no => $chapter->headings->map(fn (HsHeading $heading): array => [
                    $heading->code, $heading->name.($heading->en ? " / {$heading->en}" : ''),
                ])->all(),
            ])->all(),
            // chú giải Chương + Phần (Quy tắc 1) cho bước chọn nhóm: mục "Chương này không bao gồm" phải thấy trước khi lọc nhóm
            'notes' => (object) $chapters->mapWithKeys(fn (HsChapter $chapter): array => [$chapter->no => array_filter([
                "Chú giải Chương {$chapter->no}: {$chapter->name}" => implode("\n", $chapter->notes ?? []),
                "Chú giải Phần {$chapter->section?->no}: {$chapter->section?->name}" => implode("\n", $chapter->section?->notes ?? []),
            ])])->all(),
            // chỉ các mục "Chương này không bao gồm…" của chương và Phần, cho lượt chọn chương thứ hai khi Jev chưa chắc
            'loai_tru' => (object) $chapters->mapWithKeys(fn (HsChapter $chapter): array => [$chapter->no => [...self::exclusionLines($chapter->notes ?? []), ...self::exclusionLines($chapter->section?->notes ?? [])],
            ])->filter()->map(fn (array $lines): string => implode("\n", $lines))->all(),
        ];
    }

    /**
     * Các dòng "không bao gồm" của một chú giải: lấy mục được đánh số chứa cụm đó và mọi dòng con của nó
     * (vd "1. Chương này không bao gồm:" + "(a) …" + "(b) …"), dừng ở mục đánh số kế tiếp.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private static function exclusionLines(array $notes): array
    {
        $lines = [];
        $capturing = false;
        foreach ($notes as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\d+\.\s/u', $line)) {
                $capturing = mb_stripos($line, 'không bao gồm') !== false;
                if ($capturing) {
                    $lines[] = $line;
                }

                continue;
            }
            if ($capturing) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return array{display_path: string, tax: object, risk: object} */
    public function tax(HsNode $line): array
    {
        return ['display_path' => $line->display_path, 'tax' => (object) $line->tax, 'risk' => (object) $line->risk];
    }

    /** Lời 6 quy tắc tổng quát, mỗi quy tắc mở đầu bằng dòng "QUY TẮC n". */
    public function rules(TariffYear $year): string
    {
        return $year->rules->map(fn ($rule): string => "QUY TẮC {$rule->no}\n{$rule->content}")->implode("\n");
    }
}
