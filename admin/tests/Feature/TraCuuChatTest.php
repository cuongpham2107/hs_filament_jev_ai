<?php

use App\Filament\Pages\TraCuu;
use App\Models\User;
use App\Services\TariffImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Body SSE giống API /chat-messages của Dify (streaming), rút gọn còn những sự kiện khung chat dùng. */
function difyStream(array $events): string
{
    return collect($events)->map(fn (array $e): string => 'data: '.json_encode($e, JSON_UNESCAPED_UNICODE)."\n\n")
        ->prepend("event: ping\n\n")->implode('');
}

/** @return list<array<string, mixed>> */
function chatEvents(string $body): array
{
    return collect(explode("\n\n", trim($body)))
        ->map(fn (string $chunk): array => json_decode(substr($chunk, strlen('data: ')), true))->all();
}

beforeEach(function () {
    config(['hs.dify_url' => 'http://dify.test/v1', 'hs.dify_key' => 'app-test-key']);
});

it('chuyển sự kiện Dify thành các bước, căn cứ (kèm tên chương, nhóm) và thẻ kết quả', function () {
    app(TariffImporter::class)->import(2026, base_path('tests/fixtures/data'))->markActive();
    Http::fake(['dify.test/*' => Http::response(difyStream([
        ['event' => 'workflow_started', 'conversation_id' => 'c0nv-1', 'data' => []],
        ['event' => 'node_started', 'data' => ['node_id' => 'eval_chuong']],
        ['event' => 'node_started', 'data' => ['node_id' => 'code_a']],
        ['event' => 'node_finished', 'data' => ['node_id' => 'code_a', 'status' => 'succeeded',
            'outputs' => ['chuong' => '87 (90%), 73 (5%)', 'so_ung_vien' => 16]]],
        ['event' => 'node_finished', 'data' => ['node_id' => 'code_b', 'status' => 'succeeded', 'outputs' => ['nhom' => ['8714']]]],
        ['event' => 'node_started', 'data' => ['node_id' => 'code_d']],
        ['event' => 'node_finished', 'data' => ['node_id' => 'code_d', 'status' => 'succeeded',
            'outputs' => ['cac_buoc' => 'Nhóm 87.14 (91%) › Nan hoa (100%)', 'do_tin_cay' => 0.85, 'trang_thai' => 'ok']]],
        ['event' => 'message', 'conversation_id' => 'c0nv-1', 'answer' => '> 🔎 Chương Jev chọn: 87 (90%)'],
        ['event' => 'node_started', 'data' => ['node_id' => 'template_card']],
        ['event' => 'node_finished', 'data' => ['node_id' => 'template_card', 'status' => 'succeeded', 'outputs' => ['output' => '### 2. Mã HS']]],
        ['event' => 'workflow_finished', 'data' => ['status' => 'succeeded', 'outputs' => ['answer' => '...']]],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);
    $this->actingAs(User::factory()->create());

    $body = $this->post(route('tra-cuu.stream'), ['message' => 'NAN HOA XE MÁY'])->assertOk()->streamedContent();

    expect(chatEvents($body))->toBe([
        ['type' => 'conversation', 'id' => 'c0nv-1'],
        ['type' => 'step', 'id' => 'chuong', 'status' => 'running'],
        ['type' => 'step', 'id' => 'chuong', 'status' => 'done'],
        ['type' => 'step', 'id' => 'nhom', 'status' => 'running'],
        ['type' => 'reasoning', 'text' => "Chương Jev chọn (16 nhóm ứng viên):\n"
            ."- Chương 87 (90%): Xe trừ phương tiện chạy trên đường sắt hoặc đường tàu điện, và các bộ phận và phụ kiện của chúng\n"
            .'- Chương 73 (5%): ?'],
        ['type' => 'reasoning', 'text' => "Nhóm đưa vào đi cây:\n- 87.14: Bộ phận và phụ kiện của xe thuộc các nhóm từ 87.11 đến 87.13"],
        ['type' => 'step', 'id' => 'nhom', 'status' => 'done'],
        ['type' => 'step', 'id' => 'ket_luan', 'status' => 'running'],
        ['type' => 'reasoning', 'text' => "Đường đi: Nhóm 87.14 (91%) › Nan hoa (100%)\nĐộ tin cậy: 85% — đủ để kết luận."],
        ['type' => 'step', 'id' => 'ket_luan', 'status' => 'done'],
        ['type' => 'step', 'id' => 'the', 'status' => 'running'],
        ['type' => 'result', 'status' => 'ok', 'markdown' => '### 2. Mã HS'],
        ['type' => 'step', 'id' => 'the', 'status' => 'done'],
        ['type' => 'end'],
    ]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer app-test-key')
        && str_ends_with($request->url(), '/chat-messages') && $request['query'] === 'NAN HOA XE MÁY'
        && $request['conversation_id'] === '' && $request['response_mode'] === 'streaming');
});

it('trả thẻ cần bổ sung; câu trả lời tiếp theo gửi trong cùng hội thoại', function () {
    $conversation = '0f8fad5b-d9cb-469f-a165-70867728950e';
    Http::fake(['dify.test/*' => Http::response(difyStream([
        ['event' => 'node_finished', 'data' => ['node_id' => 'template_more', 'status' => 'succeeded', 'outputs' => ['output' => '### Cần bổ sung']]],
        ['event' => 'workflow_finished', 'data' => ['status' => 'succeeded']],
    ]))]);
    $this->actingAs(User::factory()->create());

    $events = chatEvents($this->post(route('tra-cuu.stream'), ['message' => 'dùng cho nam', 'conversation_id' => $conversation])->streamedContent());

    expect($events[0])->toBe(['type' => 'result', 'status' => 'can_bo_sung', 'markdown' => '### Cần bổ sung']);
    Http::assertSent(fn (Request $request): bool => $request['conversation_id'] === $conversation);
});

it('báo lỗi khi workflow xong mà không dựng được thẻ', function () {
    Http::fake(['dify.test/*' => Http::response(difyStream([
        ['event' => 'workflow_finished', 'data' => ['status' => 'failed', 'error' => 'Output danh_gia is missing.']],
    ]))]);
    $this->actingAs(User::factory()->create());

    $events = chatEvents($this->post(route('tra-cuu.stream'), ['message' => 'BU LÔNG'])->streamedContent());

    expect($events[0])->toBe(['type' => 'error', 'message' => 'Output danh_gia is missing.']);
});

it('báo lỗi dễ hiểu khi không gọi được Dify, không lộ chi tiết', function () {
    Http::fake(['dify.test/*' => Http::response('unauthorized', 401)]);
    $this->actingAs(User::factory()->create());

    $events = chatEvents($this->post(route('tra-cuu.stream'), ['message' => 'BU LÔNG'])->streamedContent());

    expect($events)->toBe([
        ['type' => 'error', 'message' => 'Không gọi được Dify. Kiểm tra Dify đang chạy và HS_DIFY_KEY.'],
        ['type' => 'end'],
    ]);
});

it('chặn người chưa đăng nhập và mô tả rỗng', function () {
    Http::fake();

    $this->postJson(route('tra-cuu.stream'), ['message' => 'BU LÔNG'])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson(route('tra-cuu.stream'), ['message' => ''])->assertUnprocessable();
    $this->postJson(route('tra-cuu.stream'), ['message' => 'BU LÔNG', 'conversation_id' => 'khong-phai-uuid'])->assertUnprocessable();
    Http::assertNothingSent();
});

it('trang tra cứu hiển thị khung chat với đường dẫn stream', function () {
    $this->actingAs(User::factory()->create());

    $this->get(TraCuu::getUrl())->assertOk()->assertSee('Tra cứu mã HS');
});
