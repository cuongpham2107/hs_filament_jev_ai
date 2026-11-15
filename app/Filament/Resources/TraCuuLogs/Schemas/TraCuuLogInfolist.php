<?php

namespace App\Filament\Resources\TraCuuLogs\Schemas;

use App\Filament\Resources\TraCuuLogs\TraCuuLogResource;
use App\Models\TraCuuLog;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TraCuuLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Lượt tra cứu')->columns(3)->schema([
                TextEntry::make('message')->label('Mô tả hàng')->columnSpanFull(),
                TextEntry::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('user.name')->label('Người tra')->placeholder('—'),
                IconEntry::make('is_follow_up')->label('Trả lời bổ sung')->boolean(),
            ]),
            Section::make('Kết quả')->columns(3)->schema([
                TextEntry::make('status')->label('Kết quả')->badge()
                    ->formatStateUsing(fn (string $state): string => TraCuuLog::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'can_bo_sung' => 'warning',
                        default => 'danger',
                    }),
                TextEntry::make('ma_hs')->label('Mã HS')->fontFamily('mono')->size('lg')->weight('bold')->placeholder('—')
                    ->formatStateUsing(fn (TraCuuLog $record): ?string => $record->formattedCode())
                    ->url(fn (TraCuuLog $record): ?string => TraCuuLogResource::codeUrl($record), shouldOpenInNewTab: true),
                TextEntry::make('do_tin_cay')->label('Độ tin cậy')->placeholder('—')
                    ->formatStateUsing(fn (?float $state): string => round(($state ?? 0) * 100, 1).'%'),
                TextEntry::make('error')->label('Lỗi')->color('danger')->columnSpanFull()->visible(fn (TraCuuLog $record): bool => $record->error !== null),
            ]),
            Section::make('Căn cứ phân loại')->collapsible()->schema([
                TextEntry::make('can_cu')->hiddenLabel()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => nl2br(e($state ?? '')))->html(),
            ]),
            Section::make('Thẻ kết quả')->collapsible()->schema([
                // khối ```echarts (biểu đồ chỉ vẽ được trong khung chat) bỏ đi khi xem lại
                TextEntry::make('the_ket_qua')->hiddenLabel()->placeholder('—')->markdown()
                    ->formatStateUsing(fn (?string $state): string => preg_replace('/```echarts\s*\n.*?\n```/s', '', $state ?? '')),
            ]),
        ]);
    }
}
