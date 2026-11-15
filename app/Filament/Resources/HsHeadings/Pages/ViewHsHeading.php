<?php

namespace App\Filament\Resources\HsHeadings\Pages;

use App\Filament\Resources\HsHeadings\HsHeadingResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewHsHeading extends ViewRecord
{
    protected static string $resource = HsHeadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tree')
                ->label('Xem dạng cây')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->url(fn (): string => TariffYearResource::getUrl('codes', ['record' => $this->getRecord()->tariff_year_id, 'nhom' => $this->getRecord()->code])),
        ];
    }
}
