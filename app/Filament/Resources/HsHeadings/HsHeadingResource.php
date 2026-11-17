<?php

namespace App\Filament\Resources\HsHeadings;

use App\Filament\Resources\HsHeadings\Pages\ListHsHeadings;
use App\Filament\Resources\HsHeadings\Pages\ViewHsHeading;
use App\Filament\Resources\HsHeadings\RelationManagers\NodesRelationManager;
use App\Models\HsHeading;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HsHeadingResource extends Resource
{
    protected static ?string $model = HsHeading::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $modelLabel = 'nhóm';

    protected static ?string $pluralModelLabel = 'Nhóm 4 số';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('chapter'))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Nhóm')->searchable()->sortable()
                    ->formatStateUsing(fn (string $state): string => substr($state, 0, 2).'.'.substr($state, 2)),
                TextColumn::make('name')->label('Tên nhóm')->wrap()->limit(250)->searchable(),
                TextColumn::make('chapter.no')->label('Chương'),
            ])
            ->filters([
                SelectFilter::make('hs_chapter_id')->label('Chương')->relationship('chapter', 'name', fn (Builder $query, $livewire): Builder => $query->where('tariff_year_id', $livewire->getOwnerRecord()->getKey()))->searchable()->preload(),
            ])
            ->recordUrl(fn (HsHeading $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Nhóm')->formatStateUsing(fn (HsHeading $record): string => substr($record->code, 0, 2).'.'.substr($record->code, 2)." {$record->name}")->columnSpanFull(),
            TextEntry::make('en')->label('Tiếng Anh')->placeholder('—')->columnSpanFull(),
            TextEntry::make('chapter.name')->label('Chương')->formatStateUsing(fn (HsHeading $record): string => "Chương {$record->chapter->no}: {$record->chapter->name}"),
            TextEntry::make('notes')->label('Ghi chú trong nhóm')->listWithLineBreaks()->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [NodesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHsHeadings::route('/'),
            'view' => ViewHsHeading::route('/{record}'),
        ];
    }
}
