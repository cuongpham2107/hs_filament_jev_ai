<?php

namespace App\Filament\Resources\TraCuuLogs\Pages;

use App\Filament\Resources\TraCuuLogs\TraCuuLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTraCuuLog extends ViewRecord
{
    protected static string $resource = TraCuuLogResource::class;

    protected static ?string $breadcrumb = 'Xem';

    public function getTitle(): string
    {
        return 'Lượt tra cứu lúc '.$this->record->created_at->format('d/m/Y H:i');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openCode')->label('Mở mã trong biểu thuế')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (): ?string => TraCuuLogResource::codeUrl($this->record), shouldOpenInNewTab: true)
                ->visible(fn (): bool => TraCuuLogResource::codeUrl($this->record) !== null),
        ];
    }
}
