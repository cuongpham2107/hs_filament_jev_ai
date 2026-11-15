<?php

namespace App\Filament\Resources\HsSections;

use App\Filament\Resources\HsSections\Pages\ListHsSections;
use App\Filament\Resources\HsSections\Pages\ViewHsSection;
use App\Filament\Resources\HsSections\RelationManagers\ChaptersRelationManager;
use App\Models\HsSection;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Phần I–XXI và chú giải Phần (Quy tắc 1). */
class HsSectionResource extends Resource
{
    protected static ?string $model = HsSection::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static ?string $modelLabel = 'phần';

    protected static ?string $pluralModelLabel = 'Phần';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('chapters'))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('no')->label('Phần'),
                TextColumn::make('name')->label('Tên phần')->wrap()->searchable(),
                TextColumn::make('chapters_count')->label('Số chương')->numeric(),
            ])
            ->recordUrl(fn (HsSection $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Phần')->formatStateUsing(fn (HsSection $record): string => "Phần {$record->no}: {$record->name}")->columnSpanFull(),
            TextEntry::make('notes')->label('Chú giải Phần')->listWithLineBreaks()->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [ChaptersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHsSections::route('/'),
            'view' => ViewHsSection::route('/{record}'),
        ];
    }
}
