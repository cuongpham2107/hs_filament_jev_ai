<?php

namespace App\Filament\Resources\TraCuuLogs\Pages;

use App\Filament\Resources\TraCuuLogs\TraCuuLogResource;
use Filament\Resources\Pages\ListRecords;

class ListTraCuuLogs extends ListRecords
{
    protected static string $resource = TraCuuLogResource::class;

    protected static ?string $title = 'Lịch sử tra cứu';

    protected static ?string $breadcrumb = 'Danh sách';
}
