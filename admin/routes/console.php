<?php

use App\Jobs\BuildTariffYear;
use App\Models\TariffYear;
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
