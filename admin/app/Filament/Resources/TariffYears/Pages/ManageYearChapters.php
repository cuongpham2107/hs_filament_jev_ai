<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Filament\Resources\HsChapters\HsChapterResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageYearChapters extends ManageRelatedRecords
{
    protected static string $resource = TariffYearResource::class;

    protected static string $relationship = 'chapters';

    protected static ?string $navigationLabel = 'Chương';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public function getTitle(): string|Htmlable
    {
        return 'Chương — biểu thuế '.$this->getOwnerRecord()->year;
    }

    public function table(Table $table): Table
    {
        return HsChapterResource::table($table);
    }
}
