<?php

use App\Filament\Pages\TraCuu;
use App\Filament\Resources\TraCuuLogs\Pages\ListTraCuuLogs;
use App\Filament\Resources\TraCuuLogs\TraCuuLogResource;
use App\Models\TraCuuLog;
use App\Models\User;
use App\Services\TariffImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('chặn người chưa đăng nhập và mô tả rỗng', function () {
    Classification::fake();

    $this->postJson(route('tra-cuu.stream'), ['message' => 'BU LÔNG'])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson(route('tra-cuu.stream'), ['message' => ''])->assertUnprocessable();
    $this->postJson(route('tra-cuu.stream'), ['message' => 'BU LÔNG', 'conversation_id' => 'khong-phai-uuid'])->assertUnprocessable();
    Classification::assertNothingClassified();
});

it('trang tra cứu hiển thị khung chat với đường dẫn stream', function () {
    $this->actingAs(User::factory()->create());

    $this->get(TraCuu::getUrl())->assertOk()->assertSee('Tra cứu mã HS');
});

it('lưu mỗi lượt tra cứu vào lịch sử, kể cả lượt lỗi', function () {
    app(TariffImporter::class)->import(2026, base_path('tests/fixtures/data'))->markActive();
    $conversation = '0f8fad5b-d9cb-469f-a165-70867728950e';
    $calls = 0;
    Classification::fake(function (ClassificationPrompt $prompt) use (&$calls): array {
        if ($calls >= 3) {
            throw new RuntimeException('TypeSafe returned HTTP 500.');
        }
        $calls++;
        $answers = [];
        foreach ($prompt->questions as $key => $question) {
            $q = $question->toArray();
            if ($q['type'] === 'noul') {
                $answers[$key] = new BooleanAnswer(0.95);

                continue;
            }
            $options = array_map('strval', array_keys($q['criteria']));
            $pick = collect(['87', '8714', 'Của mô tô', 'Nan hoa'])->map(fn (string $t): ?string => collect($options)->first(
                fn (string $o): bool => $o === $t || str_contains((string) $q['criteria'][$o], $t)))->filter()->first() ?? $options[0];
            $rest = 0.05 / max(count($options) - 1, 1);
            $answers[$key] = new ChoiceAnswer($pick, collect($options)->mapWithKeys(fn (string $o): array => [$o => $o === $pick ? 0.95 : $rest])->all(), 0.95);
        }

        return $answers;
    });

    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('tra-cuu.stream'), ['message' => 'nan hoa xe máy', 'conversation_id' => $conversation])->streamedContent();
    $this->post(route('tra-cuu.stream'), ['message' => 'BU LÔNG'])->streamedContent();

    $logs = TraCuuLog::query()->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->only(['user_id', 'message', 'conversation_id', 'is_follow_up', 'status', 'ma_hs']))->toBe([
            'user_id' => $user->id, 'message' => 'nan hoa xe máy', 'conversation_id' => $conversation, 'is_follow_up' => true,
            'status' => 'ok', 'ma_hs' => '87141020',
        ])
        ->and($logs[0]->the_ket_qua)->toContain('8714.10.20')
        ->and($logs[0]->can_cu)->toContain('87.14')
        ->and($logs[1]->only(['status', 'ma_hs', 'error']))->toBe([
            'status' => 'error', 'ma_hs' => null, 'error' => 'Không gọi được Jev. Kiểm tra TYPESAFE_API_KEY và kết nối tới TypeSafe.',
        ]);
});

it('trang lịch sử tra cứu liệt kê và xem lại từng lượt', function () {
    $this->actingAs(User::factory()->create());
    $ok = TraCuuLog::factory()->create();
    $more = TraCuuLog::factory()->needsMoreInfo()->create();

    Livewire::test(ListTraCuuLogs::class)
        ->assertCanSeeTableRecords([$ok, $more])
        ->filterTable('status', 'can_bo_sung')
        ->assertCanSeeTableRecords([$more])
        ->assertCanNotSeeTableRecords([$ok]);

    $this->get(TraCuuLogResource::getUrl('view', ['record' => $ok]))
        ->assertOk()->assertSee('8714.10.20')->assertSee('Nan hoa và ốc bắt đầu nan hoa (100%)');
});

it('chuyển hướng /admin về trang tra cứu và quản lý danh sách hội thoại', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $cid = '11111111-2222-4333-8444-555555555555';

    TraCuuLog::factory()->for($user)->create([
        'conversation_id' => $cid,
        'message' => 'NAN HOA XE MÁY',
        'ma_hs' => '87141020',
        'status' => 'ok',
    ]);
    TraCuuLog::factory()->for($other)->create([
        'conversation_id' => '99999999-2222-4333-8444-555555555555',
        'message' => 'HÀNG CỦA NGƯỜI KHÁC',
    ]);

    $this->actingAs($user);

    $this->get('/admin')->assertRedirect('/admin/tra-cuu');

    $this->getJson(route('tra-cuu.conversations.index'))
        ->assertOk()
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('conversations.0.id', $cid)
        ->assertJsonPath('conversations.0.ma_hs', '8714.10.20');

    $this->getJson(route('tra-cuu.conversations.show', ['conversationId' => $cid]))
        ->assertOk()
        ->assertJsonCount(1, 'turns')
        ->assertJsonPath('turns.0.message', 'NAN HOA XE MÁY');

    $this->deleteJson(route('tra-cuu.conversations.destroy', ['conversationId' => $cid]))
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(TraCuuLog::query()->where('conversation_id', $cid)->count())->toBe(0);
});
