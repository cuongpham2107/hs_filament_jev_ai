<?php

namespace App\Filament\Resources\TariffYears;

use App\Filament\Resources\TariffYears\Pages\ListTariffYears;
use App\Filament\Resources\TariffYears\Pages\ManageYearChapters;
use App\Filament\Resources\TariffYears\Pages\ManageYearCodes;
use App\Filament\Resources\TariffYears\Pages\ManageYearCodeSearch;
use App\Filament\Resources\TariffYears\Pages\ManageYearHeadings;
use App\Filament\Resources\TariffYears\Pages\ManageYearRules;
use App\Filament\Resources\TariffYears\Pages\ManageYearSections;
use App\Filament\Resources\TariffYears\Tables\TariffYearsTable;
use App\Models\TariffYear;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TariffYearResource extends Resource
{
    protected static ?string $model = TariffYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'năm biểu thuế';

    protected static ?string $pluralModelLabel = 'Năm biểu thuế';

    protected static ?int $navigationSort = 1;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function table(Table $table): Table
    {
        return TariffYearsTable::configure($table);
    }

    /** Bấm vào một năm: menu phụ Phần, Chương, Nhóm 4 số, Mã HS, Tra cứu mã, 6 quy tắc của riêng năm đó. */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ManageYearSections::class,
            ManageYearChapters::class,
            ManageYearHeadings::class,
            ManageYearCodes::class,
            ManageYearCodeSearch::class,
            ManageYearRules::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTariffYears::route('/'),
            'sections' => ManageYearSections::route('/{record}/phan'),
            'chapters' => ManageYearChapters::route('/{record}/chuong'),
            'headings' => ManageYearHeadings::route('/{record}/nhom'),
            'codes' => ManageYearCodes::route('/{record}/ma-hs'),
            'search' => ManageYearCodeSearch::route('/{record}/tra-cuu'),
            'rules' => ManageYearRules::route('/{record}/quy-tac'),
        ];
    }
}
