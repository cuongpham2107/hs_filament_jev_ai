<?php

use App\Ai\Agents\HsRationaleAgent;
use App\Ai\Tools\HsLookupTool;
use App\Models\TraCuuLog;
use App\Models\User;
use App\Services\TariffImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Tools\Request as ToolRequest;

uses(RefreshDatabase::class);

/**
 * Jev giả: mỗi câu choice chọn lựa chọn đầu tiên khớp một trong các cụm $targets (theo khoá hoặc nội dung) với xác suất
 * $sure, phần còn lại chia đều; câu noul trả 0.95.
 *
 * @param  list<string>  $targets
 */
function fakeJev(array $targets, float $sure = 0.95): Closure
{
    return function (ClassificationPrompt $prompt) use ($targets, $sure): array {
        $answers = [];
        foreach ($prompt->questions as $key => $question) {
            $q = $question->toArray();
            if ($q['type'] === 'noul') {
                $answers[$key] = new BooleanAnswer(0.95);

                continue;
            }
            $options = array_map('strval', array_keys($q['criteria']));
            $pick = collect($targets)->map(fn (string $t): ?string => collect($options)->first(
                fn (string $o): bool => $o === $t || str_contains((string) $q['criteria'][$o], $t)))->filter()->first() ?? $options[0];
            $rest = (1 - $sure) / max(count($options) - 1, 1);
            $answers[$key] = new ChoiceAnswer($pick, collect($options)->mapWithKeys(fn (string $o): array => [$o => $o === $pick ? $sure : $rest])->all(), $sure);
        }

        return $answers;
    };
}

beforeEach(function () {
    app(TariffImporter::class)->import(2026, base_path('tests/fixtures/data'))->markActive();
    $this->actingAs(User::factory()->create());
});

/** @return list<array<string, mixed>> */
function lookupEvents(string $body): array
{
    return collect(explode("\n\n", trim($body)))->map(fn (string $c): array => json_decode(substr($c, strlen('data: ')), true))->all();
}

it('tra cứu viết tay đi đủ các bước, ra mã và thẻ kết quả từ CSDL', function () {
    Classification::fake(fakeJev(['87', '8714', 'Của mô tô', 'Nan hoa']))->preventStrayClassifications();

    $events = lookupEvents($this->post(route('tra-cuu.stream'), ['message' => 'NAN HOA XE MÁY'])->streamedContent());

    $steps = collect($events)->where('type', 'step')->where('status', 'done')->pluck('id')->all();
    $result = collect($events)->firstWhere('type', 'result');
    expect($steps)->toBe(['chuong', 'nhom', 'cay', 'di_cay', 'ket_luan', 'the'])
        ->and($result['status'])->toBe('ok')
        ->and($result['code'])->toBe('87141020')
        ->and($result['markdown'])->toContain('8714.10.20')->toContain('### 12. Tất cả mức thuế theo hiệp định')
        ->and(collect($events)->where('type', 'reasoning')->pluck('text')->implode("\n"))
        ->toContain('- 87.14: Bộ phận và phụ kiện của xe thuộc các nhóm từ 87.11 đến 87.13')
        ->and(TraCuuLog::query()->sole()->only(['status', 'ma_hs']))->toBe(['status' => 'ok', 'ma_hs' => '87141020']);

    // lượt đi cây gửi state JSON và câu noul 'có nhóm phù hợp', như bản Dify
    Classification::assertClassified(fn (ClassificationPrompt $p): bool => is_array($p->state)
        && $p->state['mo_ta_hang_hoa'] === 'NAN HOA XE MÁY' && isset($p->questions['co_nhom_phu_hop']));
});

it('chưa đủ thông tin thì trả thẻ cần bổ sung; câu trả lời tiếp được ghép với mô tả trước', function () {
    Classification::fake(fakeJev(['87', '8714', 'CHUA_DU_THONG_TIN']));
    $first = lookupEvents($this->post(route('tra-cuu.stream'), ['message' => 'PHỤ TÙNG XE'])->streamedContent());
    $conversation = collect($first)->firstWhere('type', 'conversation')['id'];
    expect(collect($first)->firstWhere('type', 'result')['status'])->toBe('can_bo_sung');

    Classification::fake(fakeJev(['87', '8714', 'Của mô tô', 'Nan hoa']));
    $this->post(route('tra-cuu.stream'), ['message' => 'nan hoa của mô tô', 'conversation_id' => $conversation])->streamedContent();

    Classification::assertClassified(fn (ClassificationPrompt $p): bool => is_array($p->state)
        && $p->state['mo_ta_hang_hoa'] === "PHỤ TÙNG XE\nThông tin bổ sung: nan hoa của mô tô");
});

it('lỗi gọi Jev thì báo lỗi rõ ràng và đánh dấu bước lỗi', function () {
    Classification::fake(fn () => throw new RuntimeException('TypeSafe returned HTTP 500.'));

    $events = lookupEvents($this->post(route('tra-cuu.stream'), ['message' => 'BU LÔNG'])->streamedContent());

    expect($events)->toContain(['type' => 'step', 'id' => 'chuong', 'status' => 'error'])
        ->toContain(['type' => 'error', 'message' => 'Không gọi được Jev. Kiểm tra TYPESAFE_API_KEY và kết nối tới TypeSafe.']);
});

it('khi bật HS_LLM_ENABLED thì HsRationaleAgent viết mục 5 và 6, còn HsLookupTool gọi được từ Agent', function () {
    config(['hs.llm_enabled' => true]);
    Classification::fake(fakeJev(['87', '8714', 'Của mô tô', 'Nan hoa']));
    HsRationaleAgent::fake([[
        'mo_ta_khai_bao' => 'Nan hoa và ốc bắt đầu nan hoa bằng kim loại dùng cho xe mô tô, mới 100%',
        'can_cu_phan_loai' => 'Theo GRI 1 và GRI 6, phụ tùng mô tô thuộc nhóm 87.14, phân nhóm 8714.10.20.',
    ]]);

    $markdown = app(HsLookupTool::class)->handle(new ToolRequest(['description' => 'NAN HOA XE MÁY']));

    expect((string) $markdown)
        ->toContain('8714.10.20')
        ->toContain('Nan hoa và ốc bắt đầu nan hoa bằng kim loại dùng cho xe mô tô, mới 100%')
        ->toContain('Theo GRI 1 và GRI 6, phụ tùng mô tô thuộc nhóm 87.14, phân nhóm 8714.10.20.');
    HsRationaleAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '87141020'));
});
