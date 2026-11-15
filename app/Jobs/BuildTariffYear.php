<?php

namespace App\Jobs;

use App\Enums\TariffYearStatus;
use App\Models\TariffYear;
use App\Services\TariffImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Một năm biểu thuế: (đặt file Excel mới vào data/<năm>/) -> chạy parse_tariff_excel.py -> nạp vào CSDL.
 * File Excel cũ của năm đó không bị xoá mà chuyển vào data/<năm>/cu/ để quay lại khi cần.
 */
class BuildTariffYear implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $year, public ?string $uploadedExcel = null) {}

    public function handle(TariffImporter $importer): void
    {
        $tariffYear = TariffYear::query()->firstOrCreate(['year' => $this->year]);
        $tariffYear->update(['status' => TariffYearStatus::Processing, 'message' => 'Đang đọc file Excel…']);

        try {
            $dir = config('hs.data_path')."/{$this->year}";
            if ($this->uploadedExcel !== null) {
                $this->replaceExcel($dir);
            }
            $this->runExport();
            $importer->import($this->year, config('hs.data_path'));
            $this->removeExportFiles($dir);
        } catch (Throwable $e) {
            $tariffYear->update(['status' => TariffYearStatus::Failed, 'message' => mb_substr($e->getMessage(), -2000)]);
            throw $e;
        } finally {
            if ($this->uploadedExcel !== null) {
                File::delete($this->uploadedExcel);
            }
        }
    }

    private function replaceExcel(string $dir): void
    {
        File::ensureDirectoryExists($dir);
        foreach (File::glob("{$dir}/*.xlsx") as $old) {
            File::ensureDirectoryExists("{$dir}/cu");
            File::move($old, "{$dir}/cu/".now()->format('Ymd_His').'_'.basename($old));
        }
        File::copy($this->uploadedExcel, "{$dir}/".basename($this->uploadedExcel));
    }

    /**
     * File parse_tariff_excel.py sinh ra (~95 MB) chỉ là bước trung gian: đã nạp vào CSDL thì xoá,
     * giữ file Excel và thư mục cu/ để dựng lại khi cần.
     */
    private function removeExportFiles(string $dir): void
    {
        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'xlsx') {
                File::delete($file->getPathname());
            }
        }
        File::deleteDirectory("{$dir}/hs_tree");
    }

    private function runExport(): void
    {
        $result = Process::path(base_path())
            ->env(['HS_DATA_PATH' => config('hs.data_path')])
            ->timeout(config('hs.export_timeout'))
            ->run([config('hs.uv_bin'), 'run', 'scripts/src/parse_tariff_excel.py', (string) $this->year]);

        if ($result->failed()) {
            throw new RuntimeException("parse_tariff_excel.py lỗi:\n".mb_substr($result->errorOutput() ?: $result->output(), -1500));
        }
    }
}
