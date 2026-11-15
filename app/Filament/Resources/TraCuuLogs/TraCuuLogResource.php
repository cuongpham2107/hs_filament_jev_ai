<?php

namespace App\Filament\Resources\TraCuuLogs;

use App\Filament\Resources\TariffYears\TariffYearResource;
use App\Filament\Resources\TraCuuLogs\Pages\ListTraCuuLogs;
use App\Filament\Resources\TraCuuLogs\Pages\ViewTraCuuLog;
use App\Filament\Resources\TraCuuLogs\Schemas\TraCuuLogInfolist;
use App\Filament\Resources\TraCuuLogs\Tables\TraCuuLogsTable;
use App\Models\TariffYear;
use App\Models\TraCuuLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Lịch sử các lượt tra cứu trong khung chat (chỉ xem, không tạo / sửa). */
class TraCuuLogResource extends Resource
{
    protected static ?string $model = TraCuuLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Tra cứu';

    protected static ?string $navigationLabel = 'Lịch sử tra cứu';

    protected static ?string $modelLabel = 'lượt tra cứu';

    protected static ?string $pluralModelLabel = 'Lịch sử tra cứu';

    protected static ?string $slug = 'lich-su-tra-cuu';

    protected static ?int $navigationSort = 2;

    public static function getBreadcrumb(): string
    {
        return 'Lịch sử tra cứu';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return TraCuuLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TraCuuLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTraCuuLogs::route('/'),
            'view' => ViewTraCuuLog::route('/{record}'),
        ];
    }

    /** Link mở dòng mã trên trang cây Mã HS của năm biểu thuế đang dùng (như link trong khung chat). */
    public static function codeUrl(TraCuuLog $log): ?string
    {
        $year = TariffYear::current();

        return $log->ma_hs && $year ? TariffYearResource::getUrl('codes', ['record' => $year]).'?ma='.$log->ma_hs : null;
    }
}
