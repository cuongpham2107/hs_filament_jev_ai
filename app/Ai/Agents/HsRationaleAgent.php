<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;
use Throwable;

/**
 * Agent LLM tuỳ chọn (bật bằng HS_LLM_ENABLED=true): viết mục 5 (Mô tả khai báo gợi ý) và mục 6 (Căn cứ phân loại)
 * sau khi Jev đã chốt mã HS 8 số. Không bao giờ quyết định mã HS hay mức thuế.
 */
#[Temperature(0.2)]
#[Timeout(30)]
class HsRationaleAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Bạn là chuyên gia phân loại hàng hóa xuất nhập khẩu Việt Nam. Mã HS 8 số và đường dẫn biểu thuế đã được '
            .'hệ thống xác định sẵn. Nhiệm vụ của bạn chỉ gồm: (1) viết câu mô tả khai báo hải quan gợi ý rõ ràng, đầy đủ '
            .'tên hàng, chất liệu, quy cách, công dụng; (2) viết đoạn căn cứ phân loại ngắn gọn theo Quy tắc tổng quát '
            .'(GRI 1, GRI 6), nội dung nhóm/phân nhóm và Chú giải Phần/Chương được cung cấp. Tuyệt đối không đổi mã HS '
            .'và không tự bịa số hiệu văn bản hay thuế suất.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mo_ta_khai_bao' => $schema->string()
                ->description('Mô tả khai báo hải quan gợi ý (1 dòng ngắn gọn, đủ tên hàng, bản chất, công dụng)')
                ->required(),
            'can_cu_phan_loai' => $schema->string()
                ->description('Giải thích căn cứ phân loại vào mã HS đã chốt theo Chú giải và Quy tắc phân loại')
                ->required(),
        ];
    }

    /**
     * Gọi LLM sinh mô tả khai báo và căn cứ phân loại; nếu tắt hoặc lỗi thì trả mảng rỗng để ResultCard dùng bản mặc định.
     *
     * @param  array<string, mixed>  $result  đầu ra LookupResult::read
     * @param  list<array<string, mixed>>  $trees  cây các nhóm đã xét (có chú giải Phần/Chương)
     * @return array{mo_ta_khai_bao?: string, can_cu_phan_loai?: string}
     */
    public function explain(string $description, array $result, array $trees): array
    {
        if (! config('hs.llm_enabled')) {
            return [];
        }
        $heading = substr((string) $result['ma_hs'], 0, 4);
        $notes = [];
        foreach ($trees as $tree) {
            if ((string) ($tree['code'] ?? '') === $heading) {
                $notes = (array) ($tree['notes'] ?? []);
            }
        }
        $prompt = json_encode([
            'mo_ta_hang_hoa' => $description,
            'ma_hs_da_chot' => $result['ma_hs'],
            'duong_dan_bieu_thue' => $result['duong_dan'],
            'cac_buoc_jev' => $result['cac_buoc'],
            'chu_giai_phan_chuong' => $notes,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->prompt(
                $prompt,
                provider: config('hs.llm_provider'),
                model: config('hs.llm_model'),
            );

            return array_filter([
                'mo_ta_khai_bao' => trim((string) ($response['mo_ta_khai_bao'] ?? '')),
                'can_cu_phan_loai' => trim((string) ($response['can_cu_phan_loai'] ?? '')),
            ]);
        } catch (Throwable) {
            return [];
        }
    }
}
