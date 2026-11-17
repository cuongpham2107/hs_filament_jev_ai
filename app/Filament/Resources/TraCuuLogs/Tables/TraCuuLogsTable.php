<?php

namespace App\Filament\Resources\TraCuuLogs\Tables;

use App\Filament\Resources\TraCuuLogs\TraCuuLogResource;
use App\Models\TraCuuLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TraCuuLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Người tra')->toggleable(),
                TextColumn::make('message')->label('Mô tả hàng')->wrap()->limit(120)->searchable(),
                IconColumn::make('is_follow_up')->label('Bổ sung')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Kết quả')->badge()
                    ->formatStateUsing(fn (string $state): string => TraCuuLog::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'can_bo_sung' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('ma_hs')->label('Mã HS')->searchable()->fontFamily('mono')
                    ->formatStateUsing(fn (TraCuuLog $record): ?string => $record->formattedCode())
                    ->url(fn (TraCuuLog $record): ?string => TraCuuLogResource::codeUrl($record), shouldOpenInNewTab: true),
                TextColumn::make('do_tin_cay')->label('Tin cậy')->sortable()
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '' : round($state * 100, 1).'%'),
                TextColumn::make('input_tokens')->label('Token Jev')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '' : number_format($state, 0, ',', '.')),
                TextColumn::make('cost_usd')->label('Chi phí')->sortable()->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '' : '$'.number_format((float) $state, 4, ',', '.'))
                    ->summarize(Sum::make()->label('Tổng')->numeric(decimalPlaces: 4)),
                TextColumn::make('duration_ms')->label('Thời gian chạy')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '' : round($state / 1000, 1).' giây'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Kết quả')->options(TraCuuLog::STATUS_LABELS),
                SelectFilter::make('user_id')->label('Người tra')->relationship('user', 'name'),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
