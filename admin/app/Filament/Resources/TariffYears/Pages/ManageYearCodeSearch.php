<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Filament\Resources\HsNodes\HsNodeResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageYearCodeSearch extends ManageRelatedRecords
{
    protected static string $resource = TariffYearResource::class;

    protected static string $relationship = 'nodes';

    protected static ?string $navigationLabel = 'Tra cứu mã';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    public function getTitle(): string|Htmlable
    {
        return 'Tra cứu mã — biểu thuế '.$this->getOwnerRecord()->year;
    }

    public function table(Table $table): Table
    {
        return HsNodeResource::table($table);
    }
}
