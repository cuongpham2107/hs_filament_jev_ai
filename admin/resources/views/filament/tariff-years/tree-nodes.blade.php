{{-- Các dòng cùng nút cha (Quy tắc 6: chỉ so các dòng cùng cấp). $rows: các dòng của nhóm, nhóm theo parent_id. --}}
<ul class="border-s border-gray-200 ms-6 dark:border-white/10">
    @foreach ($rows[$parentKey] ?? [] as $node)
        @php
            $children = $rows[(string) $node->id] ?? null;
            $isLine = $node->path !== null;
            $code = (string) $node->code;
            $label = match (strlen($code)) {
                8 => substr($code, 0, 4).'.'.substr($code, 4, 2).'.'.substr($code, 6),
                6 => substr($code, 0, 4).'.'.substr($code, 4),
                default => '',
            };
        @endphp
        <li wire:key="n-{{ $node->id }}" x-data="{ open: true }" @if ($highlightId === $node->id) x-init="$el.scrollIntoView({ block: 'center' })" @endif>
            <div @class([
                'flex items-start gap-2 px-4 py-1.5 text-sm',
                'bg-warning-50 ring-1 ring-warning-300 dark:bg-warning-500/10' => $highlightId === $node->id,
            ])>
                @if ($children)
                    <button type="button" x-on:click="open = ! open" class="mt-0.5 shrink-0 text-gray-400">
                        <x-filament::icon icon="heroicon-m-chevron-right" class="size-4 transition" x-bind:class="open && 'rotate-90'" />
                    </button>
                @else
                    <span class="size-4 shrink-0"></span>
                @endif
                <span @class(['w-24 shrink-0 tabular-nums', 'font-semibold' => $isLine, 'text-gray-500' => ! $isLine])>{{ $label }}</span>
                <span class="flex-1">{{ $node->text }}</span>
                @if ($isLine)
                    <span class="shrink-0 text-xs text-gray-500">NK ưu đãi {{ $node->tax['NK ưu đãi'] ?? '' }}</span>
                    <button type="button" wire:click="mountAction('tax', { node: {{ $node->id }} })" class="shrink-0 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">Thuế</button>
                @endif
            </div>
            @if ($children)
                <div x-show="open">
                    @include('filament.tariff-years.tree-nodes', ['rows' => $rows, 'parentKey' => (string) $node->id])
                </div>
            @endif
        </li>
    @endforeach
</ul>
