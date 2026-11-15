<?php

namespace App\Filament\Resources\HsSections\RelationManagers;

use App\Filament\Resources\HsChapters\HsChapterResource;
use App\Models\HsChapter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChaptersRelationManager extends RelationManager
{
    protected static string $relationship = 'chapters';

    protected static ?string $title = 'Chương';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('no')->label('Chương'),
                TextColumn::make('name')->label('Tên chương')->wrap(),
                TextColumn::make('headings_count')->counts('headings')->label('Số nhóm'),
            ])
            ->recordUrl(fn (HsChapter $record): string => HsChapterResource::getUrl('view', ['record' => $record]));
    }
}
