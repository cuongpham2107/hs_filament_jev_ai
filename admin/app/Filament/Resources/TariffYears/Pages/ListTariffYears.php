<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Enums\TariffYearStatus;
use App\Filament\Resources\TariffYears\TariffYearResource;
use App\Jobs\BuildTariffYear;
use App\Models\TariffYear;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListTariffYears extends ListRecords
{
    protected static string $resource = TariffYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Tải biểu thuế Excel')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->schema([
                    TextInput::make('year')
                        ->label('Năm')
                        ->integer()
                        ->minValue(2000)
                        ->maxValue(2100)
                        ->default(now()->year)
                        ->required(),
                    FileUpload::make('file')
                        ->label('File biểu thuế (.xlsx, có sheet BT2026 hoặc tương tự)')
                        ->disk('local')
                        ->directory('tariff-uploads')
                        ->preserveFilenames()
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(102400)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $year = (int) $data['year'];
                    TariffYear::query()->updateOrCreate(['year' => $year], ['status' => TariffYearStatus::Processing, 'message' => 'Đang chờ xử lý…']);
                    BuildTariffYear::dispatch($year, Storage::disk('local')->path($data['file']));

                    Notification::make()
                        ->title("Đã nhận file biểu thuế {$year}")
                        ->body('Đang đọc Excel và nạp vào CSDL (khoảng 1–3 phút). Tải lại trang để xem trạng thái.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
