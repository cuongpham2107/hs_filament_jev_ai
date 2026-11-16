<?php

use App\Models\TraCuuLog;
use App\Services\TariffImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

uses(RefreshDatabase::class);

it('chặn request thiếu hoặc sai x-api-key', function () {
    Classification::fake();

    $this->postJson(route('api.hs.chat'), ['message' => 'NAN HOA XE MÁY'])
        ->assertUnauthorized();

    $this->withHeader('x-api-key', 'sai-key')
        ->postJson(route('api.hs.chat'), ['message' => 'NAN HOA XE MÁY'])
        ->assertUnauthorized();

    Classification::assertNothingClassified();
});

it('trả về kết quả tra cứu HS dạng JSON khi x-api-key khớp APP_KEY', function () {
    app(TariffImporter::class)->import(2026, base_path('tests/fixtures/data'))->markActive();

    Classification::fake(function (ClassificationPrompt $prompt): array {
        $targets = ['87', '8714', 'mô tô', 'Nan hoa'];
        $answers = [];
        foreach ($prompt->questions as $key => $question) {
            $q = $question->toArray();
            if ($q['type'] === 'noul') {
                $answers[$key] = new BooleanAnswer(0.95);

                continue;
            }
            $options = array_map('strval', array_keys($q['criteria']));
            $pick = collect($targets)->map(fn (string $t): ?string => collect($options)->first(
                fn (string $o): bool => str_contains($o, $t) || str_contains((string) $q['criteria'][$o], $t),
            ))->filter()->first() ?? $options[0];
            $rest = count($options) > 1 ? round((1 - 0.95) / (count($options) - 1), 6) : 0.0;
            $probs = array_combine($options, array_map(fn (string $o): float => $o === $pick ? 0.95 : $rest, $options));
            $answers[$key] = new ChoiceAnswer($pick, $probs, 0.95);
        }

        return $answers;
    });

    $appKey = (string) config('app.key');

    $response = $this->withHeader('x-api-key', $appKey)
        ->postJson(route('api.hs.chat'), [
            'message' => 'NAN HOA & CHÂN NAN HOA DÀNH CHO XE MÁY',
        ])
        ->assertOk()
        ->assertJsonStructure([
            'conversation_id',
            'status',
            'ma_hs',
            'ma_hs_formatted',
            'do_tin_cay',
            'can_cu',
            'markdown',
            'duration_ms',
        ])
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('ma_hs', '87141020')
        ->assertJsonPath('ma_hs_formatted', '8714.10.20');

    expect(TraCuuLog::query()->where('conversation_id', $response->json('conversation_id'))->whereNull('user_id')->count())->toBe(1);
});

it('trả thêm dữ liệu debug của Jev khi truyền debug=true', function () {
    app(TariffImporter::class)->import(2026, base_path('tests/fixtures/data'))->markActive();

    Classification::fake(function (ClassificationPrompt $prompt): array {
        $targets = ['87', '8714', 'mô tô', 'Nan hoa'];
        $answers = [];
        foreach ($prompt->questions as $key => $question) {
            $q = $question->toArray();
            if ($q['type'] === 'noul') {
                $answers[$key] = new BooleanAnswer(0.95);

                continue;
            }
            $options = array_map('strval', array_keys($q['criteria']));
            $pick = collect($targets)->map(fn (string $t): ?string => collect($options)->first(
                fn (string $o): bool => str_contains($o, $t) || str_contains((string) $q['criteria'][$o], $t),
            ))->filter()->first() ?? $options[0];
            $rest = count($options) > 1 ? round((1 - 0.95) / (count($options) - 1), 6) : 0.0;
            $probs = array_combine($options, array_map(fn (string $o): float => $o === $pick ? 0.95 : $rest, $options));
            $answers[$key] = new ChoiceAnswer($pick, $probs, 0.95);
        }

        return $answers;
    });

    $appKey = (string) config('app.key');

    $this->withHeader('x-api-key', $appKey)
        ->postJson(route('api.hs.chat'), [
            'message' => 'NAN HOA & CHÂN NAN HOA DÀNH CHO XE MÁY',
            'debug' => true,
        ])
        ->assertOk()
        ->assertJsonStructure([
            'debug' => [
                'chapters',
                'headings_step2',
                'picked_headings',
                'headings_step4',
                'co_nhom_phu_hop',
                'cac_buoc',
                'can_lam_ro',
                'top_codes',
                'step_probs',
            ],
        ])
        ->assertJsonPath('debug.top_codes.0.ma_hs', '87141020');
});
