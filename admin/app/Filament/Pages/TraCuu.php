<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TraCuuChat;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Trang chat tra cứu mã HS: chạy App\Ai\HsLookup trong Laravel (widget React TraCuuChat). */
class TraCuu extends Page
{
    protected string $view = 'filament.pages.tra-cuu';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Tra cứu HS';

    protected static ?string $title = 'Tra cứu mã HS';

    protected static ?string $slug = 'tra-cuu';

    protected static ?int $navigationSort = 0;

    protected function getHeaderWidgets(): array
    {
        return [TraCuuChat::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
