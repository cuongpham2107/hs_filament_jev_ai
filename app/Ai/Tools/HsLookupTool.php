<?php

namespace App\Ai\Tools;

use App\Ai\HsLookup\HsLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Tool chuẩn của Laravel AI SDK bọc HsLookup: cho phép gắn vào bất kỳ Agent hội thoại nào (HasTools)
 * để Agent tra mã HS 8 số và biểu thuế bằng Jev + CSDL.
 */
class HsLookupTool implements Tool
{
    public function __construct(private HsLookup $lookup) {}

    public function description(): Stringable|string
    {
        return 'Tra cứu mã HS 8 số, thuế suất nhập khẩu (MFN, các FTA), VAT và chính sách mặt hàng theo Biểu thuế XNK Việt Nam từ mô tả hàng hóa.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()
                ->description('Mô tả hàng hóa cần phân loại mã HS (tên hàng, chất liệu, cấu tạo, công dụng, quy cách)')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $markdown = '';
        $this->lookup->run((string) $request['description'], null, function (array $event) use (&$markdown): void {
            if ($event['type'] === 'result') {
                $markdown = (string) $event['markdown'];
            }
        });

        return $markdown;
    }
}
