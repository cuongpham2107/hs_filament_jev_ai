<?php

namespace App\Filament\Resources\TariffYears\Tables;

use App\Enums\TariffYearStatus;
use App\Filament\Resources\TariffYears\TariffYearResource;
use App\Jobs\BuildTariffYear;
use App\Models\TariffYear;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TariffYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->poll('10s')
            ->recordUrl(fn (TariffYear $record): ?string => $record->status === TariffYearStatus::Ready
                ? TariffYearResource::getUrl('sections', ['record' => $record]) : null)
            ->columns([
                TextColumn::make('year')->label('Năm')->sortable(),
                IconColumn::make('is_active')->label('Đang dùng')->boolean(),
                TextColumn::make('status')->label('Trạng thái')->badge(),
                TextColumn::make('source_file')->label('File nguồn')->wrap(),
                TextColumn::make('counts.chuong')->label('Chương')->numeric(),
                TextColumn::make('counts.nhom')->label('Nhóm')->numeric(),
                TextColumn::make('counts.ma')->label('Mã 8 số')->numeric(),
                TextColumn::make('imported_at')->label('Nạp lúc')->dateTime('d/m/Y H:i'),
                TextColumn::make('message')->label('Ghi chú')->wrap()->limit(300)->color('danger'),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('Dùng năm này')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (TariffYear $record): bool => ! $record->is_active && $record->status === TariffYearStatus::Ready)
                    ->requiresConfirmation()
                    ->modalDescription('Hệ thống tra cứu và API sẽ sử dụng dữ liệu của năm này khi không chỉ định năm.')
                    ->action(fn (TariffYear $record) => $record->markActive()),
                Action::make('rebuild')
                    ->label('Đọc lại Excel')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Chạy lại parse_tariff_excel.py từ file Excel đang có trong data/<năm>/ rồi nạp lại vào CSDL.')
                    ->action(function (TariffYear $record): void {
                        $record->update(['status' => TariffYearStatus::Processing, 'message' => 'Đang chờ xử lý…']);
                        BuildTariffYear::dispatch($record->year);
                        Notification::make()->title("Đang đọc lại biểu thuế {$record->year}")->success()->send();
                    }),
            ]);
    }
}
