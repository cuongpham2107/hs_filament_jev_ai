<?php

use App\Ai\HsLookup\HsLookup;
use App\Jobs\BuildTariffYear;
use App\Models\TariffYear;
use App\Services\TariffImporter;
use Illuminate\Support\Facades\Artisan;

Artisan::command('hs:import {year : Năm biểu thuế, vd 2026} {--export : Chạy lại parse_tariff_excel.py từ file Excel trong data/<năm>/ trước khi nạp}', function (TariffImporter $importer) {
    $year = (int) $this->argument('year');
    if ($this->option('export')) {
        BuildTariffYear::dispatchSync($year);
    } else {
        $importer->import($year, config('hs.data_path'));
    }
    $counts = TariffYear::query()->where('year', $year)->value('counts');
    $this->info("Đã nạp biểu thuế {$year}: ".json_encode($counts, JSON_UNESCAPED_UNICODE));
})->purpose('Nạp biểu thuế một năm từ data/<năm>/ vào CSDL');

/*
 * Chạy bộ câu test (scripts/src/benchmark_cases.py, xuất ra JSON) trên bản tra cứu viết tay (App\Ai\HsLookup).
 * Kết quả ghi storage/app/hs-benchmark.json, kèm top-4 recall và số ca sai tự tin.
 *   python3 -c "import json,sys; sys.path.insert(0,'scripts/src'); from benchmark_cases import CASES; print(json.dumps(CASES, ensure_ascii=False))" > /tmp/cases.json
 *   php artisan hs:benchmark /tmp/cases.json
 */
Artisan::command('hs:benchmark {file : JSON [[mô tả, mã kỳ vọng, "ma"|"hoi"], ...]}', function (HsLookup $lookup) {
    $cases = json_decode(file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
    $rows = [];
    foreach ($cases as $i => [$message, $want, $kind]) {
        $started = microtime(true);
        $result = null;
        $error = null;
        try {
            $lookup->run($message, null, function (array $event) use (&$result, &$error): void {
                $result = $event['type'] === 'result' ? $event : $result;
                $error = $event['type'] === 'error' ? $event['message'] : $error;
            });
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $code = (string) ($result['code'] ?? '');
        $hit = $code !== '' && str_starts_with($code, $want);
        $candidates = array_map(fn (array $c): string => (string) $c['ma_hs'], $result['candidates'] ?? []);
        $top4Hit = collect($candidates)->contains(fn (string $c): bool => str_starts_with($c, $want));
        $verdict = match (true) {
            $error !== null || $result === null => 'LỖI',
            $result['status'] === 'ok' && $kind === 'hoi' => $hit ? 'ĐOÁN (đúng hướng)' : 'SAI TỰ TIN',
            $result['status'] === 'ok' => $hit ? 'ĐÚNG' : 'SAI TỰ TIN',
            $kind === 'hoi' => $top4Hit || substr($code, 0, 4) === substr($want, 0, 4) ? 'HỎI LẠI ĐÚNG' : 'HỎI LẠI (sai hướng)',
            default => $top4Hit ? 'TOP4 (đúng)' : 'TOP4 (sai)',
        };
        $rows[] = ['cau' => $i + 1, 'mo_ta' => $message, 'ky_vong' => $want, 'ma' => $code, 'tin_cay' => $result['confidence'] ?? null,
            'top4' => $top4Hit, 'candidates' => $candidates, 'ket_luan' => $verdict,
            'usage' => $result['usage'] ?? null,
            'giay' => round(microtime(true) - $started, 1), 'loi' => $error];
        $this->line(sprintf('%2d %-22s %-9s kỳ vọng %-9s tin cậy %-5s $%-8s | %s', $i + 1, $verdict, $code, $want,
            $result['confidence'] ?? '-', number_format((float) ($result['usage']['cost_usd'] ?? 0), 5, '.', ''), mb_substr($message, 0, 55)));
    }
    file_put_contents(storage_path('app/hs-benchmark.json'), json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $this->info(json_encode(array_count_values(array_column($rows, 'ket_luan')), JSON_UNESCAPED_UNICODE));
    $top4 = count(array_filter($rows, fn (array $r): bool => $r['top4']));
    $this->info(sprintf('top-4 recall: %d/%d = %.0f%% | sai tự tin: %d', $top4, count($rows),
        count($rows) ? $top4 * 100 / count($rows) : 0, count(array_filter($rows, fn (array $r): bool => $r['ket_luan'] === 'SAI TỰ TIN'))));
    $cost = array_sum(array_map(fn (array $r): float => (float) ($r['usage']['cost_usd'] ?? 0), $rows));
    $tokens = array_sum(array_map(fn (array $r): int => (int) ($r['usage']['input_tokens'] ?? 0), $rows));
    $this->info(sprintf('chi phí: %s token vào · $%s (trung bình $%s/lượt)', number_format($tokens, 0, ',', '.'), number_format($cost, 4, '.', ''), number_format(count($rows) ? $cost / count($rows) : 0, 5, '.', '')));
})->purpose('Chạy bộ câu test trên bản tra cứu viết tay (không qua Dify)');
