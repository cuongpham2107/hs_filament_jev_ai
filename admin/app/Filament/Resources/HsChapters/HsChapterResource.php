<?php

namespace App\Filament\Resources\HsChapters;

use App\Filament\Resources\HsChapters\Pages\ListHsChapters;
use App\Filament\Resources\HsChapters\Pages\ViewHsChapter;
use App\Filament\Resources\HsChapters\RelationManagers\HeadingsRelationManager;
use App\Models\HsChapter;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HsChapterResource extends Resource
{
    protected static ?string $model = HsChapter::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'chương';

    protected static ?string $pluralModelLabel = 'Chương';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('section')->withCount('headings'))
            ->defaultSort('id')
            ->defaultGroup('section.name')
            ->columns([
                TextColumn::make('no')->label('Chương')->searchable(),
                TextColumn::make('name')->label('Tên chương')->wrap()->searchable(),
                TextColumn::make('headings_count')->label('Số nhóm')->numeric(),
            ])
            ->recordUrl(fn (HsChapter $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('section.name')->label('Phần')->formatStateUsing(fn (HsChapter $record): string => "Phần {$record->section?->no}: {$record->section?->name}"),
            TextEntry::make('name')->label('Chương')->formatStateUsing(fn (HsChapter $record): string => "Chương {$record->no}: {$record->name}"),
            TextEntry::make('section.notes')->label('Chú giải Phần')->listWithLineBreaks()->placeholder('—')->columnSpanFull(),
            TextEntry::make('notes')->label('Chú giải Chương')->listWithLineBreaks()->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [HeadingsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHsChapters::route('/'),
            'view' => ViewHsChapter::route('/{record}'),
        ];
    }
}
