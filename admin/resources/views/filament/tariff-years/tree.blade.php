@php
    $fmt = fn (?string $code): string => match (strlen((string) $code)) {
        8 => substr($code, 0, 4).'.'.substr($code, 4, 2).'.'.substr($code, 6),
        6 => substr($code, 0, 4).'.'.substr($code, 4),
        4 => substr($code, 0, 2).'.'.substr($code, 2),
        default => (string) $code,
    };
    $chevron = fn (bool $open): string => $open ? 'rotate-90' : '';
@endphp

<x-filament-panels::page>
    <form wire:submit="find" class="flex flex-wrap items-center gap-2">
        <div class="min-w-64 flex-1">
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model="search" placeholder="Tìm mã (8714, 8714.10.20) hoặc tên hàng (nan hoa)…" />
            </x-filament::input.wrapper>
        </div>
        <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">Tìm</x-filament::button>
        <x-filament::button color="gray" wire:click="collapseAll" icon="heroicon-m-minus">Thu gọn hết</x-filament::button>
    </form>

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <ul class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($sections as $section)
                @php $sectionOpen = in_array($section->id, $openSections, true); @endphp
                <li wire:key="s-{{ $section->id }}">
                    <button type="button" wire:click="toggleSection({{ $section->id }})" class="flex w-full items-start gap-2 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-white/5">
                        <x-filament::icon icon="heroicon-m-chevron-right" class="mt-0.5 size-4 shrink-0 text-gray-400 transition {{ $chevron($sectionOpen) }}" />
                        <span class="w-16 shrink-0 font-semibold text-primary-600 dark:text-primary-400">Phần {{ $section->no }}</span>
                        <span class="flex-1 text-sm font-medium">{{ $section->name }}</span>
                        <span class="shrink-0 text-xs text-gray-500">{{ $section->chapters_count }} chương</span>
                    </button>

                    @if ($sectionOpen)
                        <ul class="border-s border-gray-200 ms-6 dark:border-white/10">
                            @foreach ($chapters[$section->id] ?? [] as $chapter)
                                @php $chapterOpen = in_array($chapter->id, $openChapters, true); @endphp
                                <li wire:key="c-{{ $chapter->id }}">
                                    <button type="button" wire:click="toggleChapter({{ $chapter->id }})" class="flex w-full items-start gap-2 px-4 py-2 text-start hover:bg-gray-50 dark:hover:bg-white/5">
                                        <x-filament::icon icon="heroicon-m-chevron-right" class="mt-0.5 size-4 shrink-0 text-gray-400 transition {{ $chevron($chapterOpen) }}" />
                                        <span class="w-20 shrink-0 font-semibold">Chương {{ $chapter->no }}</span>
                                        <span class="flex-1 text-sm">{{ $chapter->name }}</span>
                                        <span class="shrink-0 text-xs text-gray-500">{{ $chapter->headings_count }} nhóm</span>
                                    </button>

                                    @if ($chapterOpen)
                                        <ul class="border-s border-gray-200 ms-6 dark:border-white/10">
                                            @foreach ($headings[$chapter->id] ?? [] as $heading)
                                                @php $headingOpen = in_array($heading->id, $openHeadings, true); @endphp
                                                <li wire:key="h-{{ $heading->id }}">
                                                    <div class="flex items-start gap-2 px-4 py-2 hover:bg-gray-50 dark:hover:bg-white/5">
                                                        <button type="button" wire:click="toggleHeading({{ $heading->id }})" class="flex flex-1 items-start gap-2 text-start">
                                                            <x-filament::icon icon="heroicon-m-chevron-right" class="mt-0.5 size-4 shrink-0 text-gray-400 transition {{ $chevron($headingOpen) }}" />
                                                            <span class="w-14 shrink-0 font-semibold">{{ $fmt($heading->code) }}</span>
                                                            <span class="flex-1 text-sm">{{ $heading->name }}</span>
                                                        </button>
                                                        <a href="{{ $headingUrl($heading) }}" class="shrink-0 text-xs text-primary-600 hover:underline dark:text-primary-400">Chú giải</a>
                                                    </div>

                                                    @if ($headingOpen)
                                                        @include('filament.tariff-years.tree-nodes', ['rows' => $nodeTrees[$heading->id] ?? collect(), 'parentKey' => ''])
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
