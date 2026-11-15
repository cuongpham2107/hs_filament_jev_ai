<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Filament\Resources\HsRules\HsRuleResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageYearRules extends ManageRelatedRecords
{
    protected static string $resource = TariffYearResource::class;

    protected static string $relationship = 'rules';

    protected static ?string $navigationLabel = '6 quy tắc';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public function getTitle(): string|Htmlable
    {
        return '6 quy tắc — biểu thuế '.$this->getOwnerRecord()->year;
    }

    public function table(Table $table): Table
    {
        return HsRuleResource::table($table);
    }
}
