<?php

namespace App\Filament\Resources\HsRules;

use App\Filament\Resources\HsRules\Pages\ListHsRules;
use App\Models\HsRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** 6 quy tắc tổng quát giải thích việc phân loại (sheet 6QT). */
class HsRuleResource extends Resource
{
    protected static ?string $model = HsRule::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $modelLabel = 'quy tắc';

    protected static ?string $pluralModelLabel = '6 quy tắc phân loại';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('no')
            ->paginated(false)
            ->columns([
                TextColumn::make('no')->label('Quy tắc'),
                TextColumn::make('content')->label('Nội dung')->wrap()->formatStateUsing(fn (string $state): string => nl2br(e($state)))->html(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListHsRules::route('/')];
    }
}
