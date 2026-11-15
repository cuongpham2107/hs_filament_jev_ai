<?php

namespace App\Services\HsLookup;

/**
 * Thẻ markdown trả cho khung chat, chuyển từ node 'Ghép kết quả (không LLM)' và hai template của workflow Dify
 * ('Định dạng thẻ kết quả', 'Định dạng thẻ cần bổ sung'). Không có LLM: mọi nội dung lấy từ dữ liệu biểu thuế và
 * các bước Jev đã chọn.
 */
class ResultCard
{
    private const COUNTRY_FTAS = [
        'Trung Quốc' => [['ACFTA', 'Form E (ACFTA)'], ['RCEPT-C', 'RCEP (TQ)']],
        'Hàn Quốc' => [['AKFTA', 'Form AK (AKFTA)'], ['VKFTA', 'Form VK (VKFTA)'], ['RCEPT-E', 'RCEP (HQ)']],
        'Nhật Bản' => [['AJCEP', 'Form AJ (AJCEP)'], ['VJEPA', 'Form JV (VJEPA)'], ['CPTPP', 'CPTPP'], ['RCEPT-D', 'RCEP (NB)']],
        'ASEAN' => [['ATIGA', 'Form D (ATIGA)'], ['RCEPT-A', 'RCEP (ASEAN)']],
        'EU' => [['EVFTA', 'EUR.1 (EVFTA)']],
        'Anh' => [['UKVFTA', 'EUR.1 (UKVFTA)']],
        'Úc / New Zealand' => [['AANZFTA', 'Form AANZ'], ['CPTPP', 'CPTPP'], ['RCEPT-B', 'RCEP (Úc)'], ['RCEPT-F', 'RCEP (NZ)']],
        'Ấn Độ' => [['AIFTA', 'Form AI (AIFTA)']],
        'EAEU (Nga, Belarus...)' => [['VN-EAEU', 'Form EAV (VN-EAEU)']],
        'Chile' => [['VCFTA', 'Form VC (VCFTA)'], ['CPTPP', 'CPTPP']],
        'Hồng Kông' => [['AHKFTA', 'Form AHK (AHKFTA)']],
        'Cuba' => [['VNCU', 'Form VNCB (VNCU)']],
        'Lào' => [['VN-LAO', 'Form S (VN-LAO)']],
        'Israel' => [['VIFTA', 'VIFTA']],
    ];

    /** Nhận diện xuất xứ trong mô tả; không thấy thì mặc định Trung Quốc (như bản Dify). */
    private const COUNTRY_ALIASES = [
        'Trung Quốc' => 'trung quốc|china|tq|prc',
        'Hàn Quốc' => 'hàn quốc|korea|hq|kr',
        'Nhật Bản' => 'nhật bản|nhật|japan|jp',
        'ASEAN' => 'asean|thái lan|thailand|malaysia|indonesia|singapore|philippines|việt nam',
        'EU' => 'eu|châu âu|đức|pháp|ý|italia|tây ban nha|hà lan|bỉ',
        'Anh' => 'anh|uk|united kingdom|great britain',
        'Úc / New Zealand' => 'úc|australia|new zealand|nz',
        'Ấn Độ' => 'ấn độ|india',
        'EAEU (Nga, Belarus...)' => 'nga|russia|belarus|eaeu',
        'Chile' => 'chile',
        'Hồng Kông' => 'hồng kông|hong kong|hk',
        'Lào' => 'lào|laos',
        'Israel' => 'israel',
    ];

    private const ALL_FTA_COLUMNS = ['ACFTA', 'ATIGA', 'AJCEP', 'VJEPA', 'AKFTA', 'AANZFTA', 'AIFTA', 'VKFTA', 'VCFTA',
        'VN-EAEU', 'CPTPP', 'AHKFTA', 'VNCU', 'EVFTA', 'UKVFTA', 'VN-LAO', 'VIFTA', 'RCEPT-A', 'RCEPT-B', 'RCEPT-C',
        'RCEPT-D', 'RCEPT-E', 'RCEPT-F'];

    private const OTHER_TAXES = [
        'TT ĐB' => 'Thuế tiêu thụ đặc biệt (TTĐB)', 'Thuế BV MT' => 'Thuế bảo vệ môi trường (BVMT)',
        'XK' => 'Thuế xuất khẩu', 'XK CP TPP' => 'Thuế XK CPTPP', 'XK EV' => 'Thuế XK EVFTA', 'XK UKV' => 'Thuế XK UKVFTA',
    ];

    /**
     * Thẻ kết quả 12 mục khi đã xác định được mã.
     *
     * @param  array<string, mixed>  $result  đầu ra LookupResult::read
     * @param  array{display_path?: ?string, tax?: array<string, mixed>, risk?: array<string, mixed>}  $taxDoc  TariffDocuments::tax
     */
    public function ok(array $result, array $taxDoc, string $message, int $year): string
    {
        $code = $result['ma_hs'];
        $tax = (array) ($taxDoc['tax'] ?? []);
        $risk = (array) ($taxDoc['risk'] ?? []) ?: ['level' => 'Thấp', 'reason' => 'Không nằm trong danh mục rủi ro'];
        $unit = $tax['Đơn vị tính'] ?? '';
        $country = self::country($message);
        $mfn = self::rate($tax['NK ưu đãi'] ?? '');

        $md = "### 1. Mặt hàng\n**{$message}**\n\n";
        $md .= "### 2. Mã HS\n<span style=\"color:#d92d20;font-weight:700;font-size:1.25em\">".self::code($code).'</span>';
        $md .= $tax ? ' `(Có trong Biểu thuế '.$year.' — Độ tin cậy: '.round($result['do_tin_cay'] * 100, 1)."%)`\n\n" : "\n\n";
        $md .= "### 3. Theo biểu thuế\n".($taxDoc['display_path'] ?: $result['duong_dan']).($unit ? " · ĐVT: {$unit}" : '')."\n\n";
        $md .= "### 4. Cảnh báo rủi ro\n".match ($risk['level'] ?? 'Thấp') {
            'Cao' => '🔴 **Rủi ro cao**: ',
            'Trung bình' => '🟡 **Rủi ro trung bình**: ',
            default => '🟢 **Rủi ro thấp**: ',
        }.($risk['reason'] ?? 'Không nằm trong danh mục rủi ro')."\n\n";
        $md .= "### 5. Mô tả khai báo gợi ý\n```text\n{$message}\n```\n\n";
        $md .= "### 6. Căn cứ phân loại\nQuy tắc 1 (chọn nhóm) rồi Quy tắc 6 (từng cấp cây), xác suất Jev ở mỗi bước: {$result['cac_buoc']}\n\n";

        $md .= "### 7. Thuế nhập khẩu\n- **Thông thường**: ".self::rate($tax['NK TT'] ?? '')." · **Ưu đãi (MFN)**: {$mfn}\n";
        $forms = self::COUNTRY_FTAS[$country];
        $md .= "- **Ưu đãi đặc biệt cho hàng xuất xứ {$country} (có C/O hợp lệ)**:\n";
        $best = null;
        foreach ($forms as [$column, $form]) {
            $md .= "  - {$form}: **".self::rate($tax[$column] ?? '')."**\n";
            $number = self::number($tax[$column] ?? '');
            if ($number !== null && ($best === null || $number < $best[0])) {
                $best = [$number, self::rate($tax[$column] ?? '').' ('.$form.')'];
            }
        }
        $md .= '- 👉 **Có lợi nhất**: '.($best[1] ?? self::rate($tax[$forms[0][0]] ?? '')." ({$forms[0][1]})")."\n";
        $md .= "- **Không có C/O hợp lệ**: áp MFN **{$mfn}**\n\n";
        $md .= "### 8. VAT\n".self::vat($tax['VAT'] ?? '', $tax['Giảm VAT'] ?? '')."\n\n";

        $others = [];
        foreach (self::OTHER_TAXES as $column => $label) {
            $value = trim((string) ($tax[$column] ?? ''));
            if ($value !== '' && $value !== '0') {
                $others[] = "- **{$label}**: ".self::rate($value);
            }
        }
        if ($others) {
            $md .= "### 9. Thuế khác\n".implode("\n", $others)."\n\n";
        }

        $policies = array_filter(array_map('trim', explode(';', (string) ($tax['Chính sách mặt hàng theo mã HS'] ?? ''))));
        $md .= "### 10. Chính sách mặt hàng\n".($policies ? '- '.implode("\n- ", $policies)
            : "- Không có văn bản quản lý chuyên ngành riêng theo mã HS trong Biểu thuế {$year}.")."\n\n";

        $md .= "### 11. Mã dễ nhầm\n";
        $confusable = array_filter($result['de_nham_list'], fn (array $r): bool => isset($result['paths'][$r[0]]) && $r[0] !== $code);
        $md .= $confusable ? implode("\n", array_map(function (array $r) use ($result): string {
            $path = $result['paths'][$r[0]];
            $name = str_contains($path, ' › ') ? last(explode(' › ', $path)) : last(explode(' > ', $path));

            return '- **`'.self::code($r[0])."`** ({$name}): Jev chấm ".LookupResult::percent($r[1]);
        }, $confusable)) : '- Không có mã dễ nhầm đáng kể trong cùng phân nhóm.';
        $md .= "\n\n".self::chart($result['chart_echarts']);

        $md .= "### 12. Tất cả mức thuế theo hiệp định\n| Hiệp định | Thuế suất | Hiệp định | Thuế suất | Hiệp định | Thuế suất |\n| :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach (array_chunk(self::ALL_FTA_COLUMNS, 3) as $row) {
            $cells = [];
            foreach ([0, 1, 2] as $i) {
                $cells[] = $row[$i] ?? '';
                $cells[] = isset($row[$i]) ? self::rate($tax[$row[$i]] ?? '') : '';
            }
            $md .= '| '.implode(' | ', $cells)." |\n";
        }

        return $md;
    }

    /** Thẻ khi chưa đủ căn cứ: mã đang nghiêng về, điều cần làm rõ, các bước, các mã đang cân nhắc. */
    public function needsMoreInfo(array $result, string $message): string
    {
        $code = $result['ma_hs'];
        $md = "### ⚠️ Cần bổ sung thông tin\nChưa đủ căn cứ để kết luận mã HS cho mô tả:\n```text\n{$message}\n```\n\n";
        $md .= "### 1. Hệ thống đang nghiêng về\n".(strlen($code) === 8
            ? '<span style="color:#b54708;font-weight:700;font-size:1.25em">'.self::code($code).'</span> `(Độ tin cậy '
                .round($result['do_tin_cay'] * 100, 1).'% — chưa đủ để kết luận)`'
            : '**Chưa xác định được nhóm phù hợp** trong các nhóm đã xét.')."\n\n";
        $md .= "### 2. Cần làm rõ\n👉 {$result['can_lam_ro']}\n\n";
        $md .= "### 3. Các bước đã phân loại\n".($result['cac_buoc'] ?: '—')."\n\n";
        $md .= "### 4. Các mã đang cân nhắc\n";
        $md .= $result['de_nham_list'] ? implode("\n", array_map(fn (array $r): string => '- `'.self::code($r[0]).'` ('
            .LookupResult::percent($r[1]).'): '.($result['paths'][$r[0]] ?? ''), $result['de_nham_list']))
            : '- Không có mã nào khác đáng kể.';

        return $md."\n\n".self::chart($result['chart_echarts']);
    }

    private static function chart(string $option): string
    {
        return "**So sánh xác suất các mã (Jev):**\n\n```echarts\n{$option}\n```\n\n";
    }

    private static function code(string $code): string
    {
        return strlen($code) === 8 ? substr($code, 0, 4).'.'.substr($code, 4, 2).'.'.substr($code, 6) : $code;
    }

    /** "5" -> "5%", "7.5" -> "7,5%", rỗng -> "—", chữ (vd "13 (-BN, ID)") giữ nguyên. */
    private static function rate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }

        return preg_match('/^\d+(\.\d+)?$/', $value) ? str_replace('.', ',', $value).'%' : $value;
    }

    private static function number(mixed $value): ?float
    {
        return preg_match('/^(\d+(?:\.\d+)?)/', str_replace(',', '.', trim((string) $value)), $m) ? (float) $m[1] : null;
    }

    private static function country(string $message): string
    {
        $text = mb_strtolower($message);
        foreach (self::COUNTRY_ALIASES as $country => $pattern) {
            if (preg_match("/(*UCP)\\b({$pattern})\\b/u", $text)) {
                return $country;
            }
        }

        return 'Trung Quốc';
    }

    private static function vat(mixed $vat, mixed $noReduction): string
    {
        $vat = trim((string) $vat);
        $noReduction = trim((string) $noReduction);
        if ($vat === '') {
            return 'Không có thông tin';
        }
        if ($vat === '*') {
            return 'Không thuộc đối tượng chịu thuế GTGT (*)';
        }
        if (str_contains($vat, '8/10') || str_contains($vat, '10/8')) {
            return $noReduction === '' ? '10% (được giảm còn 8% đến hết 31/12/2026 — NĐ 174/2025/NĐ-CP)'
                : "10% (Không được giảm VAT — {$noReduction})";
        }

        return "{$vat}%".($noReduction !== '' ? " (Không giảm VAT: {$noReduction})" : '');
    }
}
