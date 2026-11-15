<?php

namespace App\Filament\Resources\HsHeadings\RelationManagers;

use App\Models\HsNode;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Cây của nhóm theo đúng thứ tự dòng biểu thuế; thụt lề theo cấp gạch ngang (Quy tắc 6: so các dòng cùng cấp). */
class NodesRelationManager extends RelationManager
{
    protected static string $relationship = 'nodes';

    protected static ?string $title = 'Cây phân nhóm và mã 8 số';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->paginated([20, 50, 100, 'all'])
            ->defaultPaginationPageOption(100)
            ->columns([
                TextColumn::make('code')->label('Mã')
                    ->formatStateUsing(fn (HsNode $record): string => $record->code ? $record->formattedCode() : '')
                    ->placeholder('—')
                    ->weight(fn (HsNode $record): ?string => strlen((string) $record->code) === 8 ? 'bold' : null),
                TextColumn::make('text')->label('Mô tả')->wrap()
                    ->formatStateUsing(fn (HsNode $record): string => str_repeat('–  ', $record->level).$record->text),
                TextColumn::make('tax.NK ưu đãi')->label('NK ưu đãi')->placeholder(''),
                TextColumn::make('tax.VAT')->label('VAT')->placeholder(''),
            ]);
    }
}
