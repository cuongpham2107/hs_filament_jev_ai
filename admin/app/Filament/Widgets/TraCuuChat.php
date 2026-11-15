<?php

namespace App\Filament\Widgets;

use App\Ai\HsLookup\HsLookup;
use App\Filament\Resources\TariffYears\TariffYearResource;
use App\Models\TariffYear;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Vite;

/**
 * Khung chat tra cứu mã HS (React, resources/js/components/tra-cuu). Gửi mô tả hàng tới route tra-cuu.stream,
 * hiển thị các bước workflow (Plan), căn cứ Jev (Reasoning), hàng đợi nhiều mặt hàng (Queue) và thẻ kết quả.
 */
class TraCuuChat extends Widget
{
    use HasJsRenderer;

    /** Chỉ đặt trên trang TraCuu, không tự lên Dashboard. */
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /** Hiện ngay khi tải trang (không hiện khối 'Loading…' trắng trước). */
    protected static ?bool $isLazyLoaded = false;

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/tra-cuu-chat.tsx');
    }

    /** @return array{streamUrl: string, csrfToken: string, cssUrl: string, steps: list<array{id: string, label: string}>, codeUrl: ?string} */
    public function getRendererConfiguration(): array
    {
        return [
            'streamUrl' => route('tra-cuu.stream'),
            'csrfToken' => csrf_token(),
            'cssUrl' => Vite::asset('resources/css/tra-cuu-chat.css'),
            'steps' => array_map(
                fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
                array_keys(HsLookup::STEPS), HsLookup::STEPS,
            ),
            // trang cây Mã HS của năm đang dùng: khung chat thêm ?ma= / ?nhom= để mở bản ghi mã, nhóm
            'codeUrl' => ($year = TariffYear::current()) ? TariffYearResource::getUrl('codes', ['record' => $year]) : null,
        ];
    }
}
