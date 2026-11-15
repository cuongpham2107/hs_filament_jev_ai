<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Filament\Resources\HsSections\HsSectionResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageYearSections extends ManageRelatedRecords
{
    protected static string $resource = TariffYearResource::class;

    protected static string $relationship = 'sections';

    protected static ?string $navigationLabel = 'Phần';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    public function getTitle(): string|Htmlable
    {
        return 'Phần — biểu thuế '.$this->getOwnerRecord()->year;
    }

    public function table(Table $table): Table
    {
        return HsSectionResource::table($table);
    }
}
