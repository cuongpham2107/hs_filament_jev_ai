<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Filament\Resources\HsHeadings\HsHeadingResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageYearHeadings extends ManageRelatedRecords
{
    protected static string $resource = TariffYearResource::class;

    protected static string $relationship = 'headings';

    protected static ?string $navigationLabel = 'Nhóm 4 số';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    public function getTitle(): string|Htmlable
    {
        return 'Nhóm 4 số — biểu thuế '.$this->getOwnerRecord()->year;
    }

    public function table(Table $table): Table
    {
        return HsHeadingResource::table($table);
    }
}
