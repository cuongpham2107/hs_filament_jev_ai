<?php

namespace App\Services;

use App\Enums\TariffYearStatus;
use App\Models\TariffYear;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nạp kết quả của scripts/src/parse_tariff_excel.py (data/<năm>/) vào các bảng cây Phần -> Chương -> Nhóm -> nút -> mã.
 * Phân tích Excel và các giá trị suy ra (nhãn 'Loại khác', phân nhóm 6 số, đường dẫn, rủi ro) chỉ nằm ở Python;
 * ở đây chỉ đọc lại các file nó sinh ra.
 */
class TariffImporter
{
    public function import(int $year, string $dataPath): TariffYear
    {
        // ponytail: hs_tree.json ~19 MB (kèm thuế mọi dòng), json_decode cần ~110 MB > mặc định 128 MB của PHP CLI.
        ini_set('memory_limit', '1G');
        $dir = rtrim($dataPath, '/')."/{$year}";
        $tree = $this->readJson("{$dir}/hs_tree.json");
        $rules = $this->parseRules($this->readFile("{$dir}/hs_rules.txt"));

        return DB::transaction(function () use ($year, $dir, $tree, $rules): TariffYear {
            $tariffYear = TariffYear::query()->firstOrCreate(['year' => $year]);
            $tariffYear->sections()->delete();   // xoá dây chuyền chương, nhóm, nút
            $tariffYear->chapters()->delete();   // chương không thuộc phần nào
            $tariffYear->rules()->delete();
            $yearId = $tariffYear->id;

            foreach ($rules as $no => $content) {
                DB::table('hs_rules')->insert(['tariff_year_id' => $yearId, 'no' => $no, 'content' => $content]);
            }

            $sectionIds = [];
            foreach ($tree['sections'] as $section) {
                $sectionIds[$section['no']] = DB::table('hs_sections')->insertGetId([
                    'tariff_year_id' => $yearId, 'no' => $section['no'], 'name' => $section['name'],
                    'notes' => $this->json($section['notes']),
                ]);
            }

            $chapterIds = [];
            foreach ($tree['chapters'] as $chapter) {
                $chapterIds[$chapter['no']] = DB::table('hs_chapters')->insertGetId([
                    'tariff_year_id' => $yearId, 'hs_section_id' => $sectionIds[$chapter['section']] ?? null,
                    'no' => $chapter['no'], 'name' => $chapter['name'], 'notes' => $this->json($chapter['notes']),
                ]);
            }

            $codeCount = $nodeCount = 0;
            foreach ($tree['headings'] as $heading) {
                $doc = $this->readJson("{$dir}/hs_tree/{$heading['code']}.json");
                if (count($doc['nodes']) !== count($heading['nodes'])) {
                    throw new RuntimeException("Nhóm {$heading['code']}: số nút trong hs_tree.json và hs_tree/{$heading['code']}.json khác nhau.");
                }
                $headingId = DB::table('hs_headings')->insertGetId([
                    'tariff_year_id' => $yearId, 'hs_chapter_id' => $chapterIds[$heading['chapter']],
                    'code' => $heading['code'], 'name' => $heading['name'], 'en' => $heading['en'],
                    'notes' => $this->json($heading['notes']), 'biz_notes' => $this->json($heading['biz_notes']),
                    'subheadings' => $this->json((object) $doc['subheadings']),
                ]);

                $nodeIds = [];
                foreach ($heading['nodes'] as $position => $node) {
                    $code = $node['code'];
                    $line = $code !== null ? ($doc['codes'][$code] ?? null) : null;
                    $taxDoc = $line !== null ? $this->readJson("{$dir}/hs_tree/tax/{$code}.json") : null;
                    $nodeIds[$position] = DB::table('hs_nodes')->insertGetId([
                        'tariff_year_id' => $yearId, 'hs_heading_id' => $headingId,
                        'parent_id' => $node['parent'] === null ? null : $nodeIds[$node['parent']],
                        'position' => $position, 'level' => $node['level'], 'code' => $code,
                        'text' => $node['text'], 'label' => $doc['nodes'][$position]['label'],
                        'en' => $node['en'], 'note' => $node['note'],
                        'subheading' => $line['sub'] ?? null, 'path' => $line['path'] ?? null,
                        'path_en' => $line['en'] ?? null, 'display_path' => $taxDoc['display_path'] ?? null,
                        'tax' => $taxDoc ? $this->json((object) $taxDoc['tax']) : null,
                        'risk' => $taxDoc ? $this->json((object) $taxDoc['risk']) : null,
                    ]);
                    $codeCount += $line !== null ? 1 : 0;
                }
                $nodeCount += count($nodeIds);
            }

            $tariffYear->update([
                'source_file' => $tree['source'] ?? null,
                'status' => TariffYearStatus::Ready,
                'message' => null,
                'counts' => ['chuong' => count($chapterIds), 'nhom' => count($tree['headings']), 'nut' => $nodeCount, 'ma' => $codeCount],
                'imported_at' => now(),
            ]);

            return $tariffYear;
        });
    }

    /** @return array<int, string> số quy tắc => nội dung (không gồm dòng tiêu đề "QUY TẮC n") */
    private function parseRules(string $text): array
    {
        $rules = [];
        $current = null;
        foreach (explode("\n", str_replace("\r\n", "\n", trim($text))) as $line) {  // không dùng /\R/: thiếu cờ u thì cắt vỡ ký tự UTF-8 chứa byte 0x85
            if (preg_match('/^QUY TẮC (\d+)$/u', $line, $match)) {
                $current = (int) $match[1];
                $rules[$current] = [];
            } elseif ($current !== null) {
                $rules[$current][] = $line;
            }
        }
        if (count($rules) !== 6) {
            throw new RuntimeException('hs_rules.txt phải có đủ 6 quy tắc, đang có '.count($rules).'.');
        }

        return array_map(fn (array $lines): string => implode("\n", $lines), $rules);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function readJson(string $path): array
    {
        return json_decode($this->readFile($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("Thiếu file {$path}. Chạy parse_tariff_excel.py cho năm này trước.");
        }

        return file_get_contents($path);
    }
}
