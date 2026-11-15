<?php

namespace App\Filament\Widgets;

use App\Services\DifyRunEvents;
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

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/tra-cuu-chat.tsx');
    }

    /** @return array{streamUrl: string, csrfToken: string, cssUrl: string, steps: list<array{id: string, label: string}>} */
    public function getRendererConfiguration(): array
    {
        return [
            'streamUrl' => route('tra-cuu.stream'),
            'csrfToken' => csrf_token(),
            'cssUrl' => Vite::asset('resources/css/tra-cuu-chat.css'),
            'steps' => array_map(
                fn (string $id, array $step): array => ['id' => $id, 'label' => $step['label']],
                array_keys(DifyRunEvents::STEPS), DifyRunEvents::STEPS,
            ),
        ];
    }
}
