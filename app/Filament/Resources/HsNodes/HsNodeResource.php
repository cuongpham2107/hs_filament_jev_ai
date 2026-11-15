<?php

namespace App\Filament\Resources\HsNodes;

use App\Filament\Resources\TariffYears\TariffYearResource;
use App\Models\HsNode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Định nghĩa cây và bảng mã HS; hiển thị trong trang năm (Mã HS, Tra cứu mã). */
class HsNodeResource extends Resource
{
    protected static ?string $model = HsNode::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $modelLabel = 'mã HS';

    protected static ?string $pluralModelLabel = 'Mã HS';

    protected static ?string $slug = 'ma-hs';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->tariffLines()->with('heading'))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Mã HS')->sortable()
                    ->formatStateUsing(fn (HsNode $record): string => $record->formattedCode())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('code', 'like', str_replace(['.', ' '], '', $search).'%')),
                TextColumn::make('heading.name')->label('Nhóm')->wrap()->limit(80)->toggleable(),
                TextColumn::make('path')->label('Đường dẫn trong nhóm')->wrap()->limit(220)->searchable(),
                TextColumn::make('path_en')->label('Tiếng Anh')->wrap()->limit(120)->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                self::taxAction(),
                Action::make('tree')->label('Xem trong cây')->icon(Heroicon::OutlinedFolderOpen)
                    ->url(fn (HsNode $record): string => TariffYearResource::getUrl('codes', ['record' => $record->tariff_year_id, 'nhom' => $record->heading->code])),
            ]);
    }

    public static function taxAction(): Action
    {
        return Action::make('tax')
            ->label('Thuế')
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (HsNode $record): bool => $record->path !== null)
            ->modalHeading(fn (HsNode $record): string => 'Mã '.$record->formattedCode())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->schema([
                TextEntry::make('display_path')->label('Theo biểu thuế'),
                TextEntry::make('note')->label('Ghi chú')->placeholder('—'),
                KeyValueEntry::make('tax')->label('Thuế suất và chính sách')->keyLabel('Cột')->valueLabel('Giá trị')
                    ->state(fn (HsNode $record): array => array_filter($record->tax ?? [], fn ($value): bool => $value !== '')),
                KeyValueEntry::make('risk')->label('Rủi ro'),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return []; // chỉ dùng qua trang năm: Năm biểu thuế › Mã HS (cây) và › Tra cứu mã (bảng)
    }
}
