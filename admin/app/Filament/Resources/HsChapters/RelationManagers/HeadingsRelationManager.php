<?php

namespace App\Filament\Resources\HsChapters\RelationManagers;

use App\Filament\Resources\HsHeadings\HsHeadingResource;
use App\Models\HsHeading;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HeadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'headings';

    protected static ?string $title = 'Nhóm 4 số';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Nhóm')->formatStateUsing(fn (string $state): string => substr($state, 0, 2).'.'.substr($state, 2)),
                TextColumn::make('name')->label('Tên nhóm')->wrap()->searchable(),
                TextColumn::make('nodes_count')->counts('nodes')->label('Số dòng'),
            ])
            ->recordUrl(fn (HsHeading $record): string => HsHeadingResource::getUrl('view', ['record' => $record]));
    }
}
