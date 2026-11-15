<?php

use App\Jobs\BuildTariffYear;
use App\Models\TariffYear;
use App\Services\HsLookup\HsLookup;
use App\Services\TariffImporter;
use Illuminate\Support\Facades\Artisan;

Artisan::command('hs:import {year : Năm biểu thuế, vd 2026} {--export : Chạy lại hs_export.py từ file Excel trong data/<năm>/ trước khi nạp}', function (TariffImporter $importer) {
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
 * Chạy bộ câu test (scripts/cases_moi.py, xuất ra JSON) trên bản tra cứu viết tay (App\Services\HsLookup) để so với
 * bản Dify (scripts/test_song_song.py). Chấm như test_song_song.py; kết quả ghi test_results_laravel.json ở gốc dự án.
 *   python3 -c "import json,sys; sys.path.insert(0,'scripts'); from cases_moi import CASES; print(json.dumps(CASES, ensure_ascii=False))" > /tmp/cases.json
 *   php admin/artisan hs:benchmark /tmp/cases.json
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
        $verdict = match (true) {
            $error !== null || $result === null => 'LỖI',
            $result['status'] === 'ok' && $kind === 'hoi' => $hit ? 'ĐOÁN (đúng hướng)' : 'SAI TỰ TIN',
            $result['status'] === 'ok' => $hit ? 'ĐÚNG' : 'SAI TỰ TIN',
            $kind === 'hoi' => $hit || substr($code, 0, 4) === substr($want, 0, 4) ? 'HỎI LẠI ĐÚNG' : 'HỎI LẠI (sai hướng)',
            default => $hit ? 'HỎI LẠI (mã đầu đúng)' : 'HỎI LẠI (mã đầu sai)',
        };
        $rows[] = ['cau' => $i + 1, 'mo_ta' => $message, 'ky_vong' => $want, 'ma' => $code, 'tin_cay' => $result['confidence'] ?? null,
            'ket_luan' => $verdict, 'giay' => round(microtime(true) - $started, 1), 'loi' => $error];
        $this->line(sprintf('%2d %-22s %-9s kỳ vọng %-9s tin cậy %s | %s', $i + 1, $verdict, $code, $want, $result['confidence'] ?? '-', mb_substr($message, 0, 55)));
    }
    file_put_contents(base_path('../test_results_laravel.json'), json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $this->info(json_encode(array_count_values(array_column($rows, 'ket_luan')), JSON_UNESCAPED_UNICODE));
})->purpose('Chạy bộ câu test trên bản tra cứu viết tay (không qua Dify)');
