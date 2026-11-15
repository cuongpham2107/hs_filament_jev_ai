<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TariffYearStatus: string implements HasColor, HasLabel
{
    case Processing = 'dang_xu_ly';
    case Ready = 'san_sang';
    case Failed = 'loi';

    public function getLabel(): string
    {
        return match ($this) {
            self::Processing => 'Đang xử lý',
            self::Ready => 'Sẵn sàng',
            self::Failed => 'Lỗi',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Processing => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
