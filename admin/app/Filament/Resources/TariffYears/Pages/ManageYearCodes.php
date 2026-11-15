<?php

namespace App\Filament\Resources\TariffYears\Pages;

use App\Filament\Resources\HsHeadings\HsHeadingResource;
use App\Filament\Resources\HsNodes\HsNodeResource;
use App\Filament\Resources\TariffYears\TariffYearResource;
use App\Models\HsChapter;
use App\Models\HsHeading;
use App\Models\HsNode;
use App\Models\HsSection;
use App\Models\TariffYear;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Cả biểu thuế một năm dạng cây: Phần -> Chương -> Nhóm 4 số -> phân nhóm / dòng gạch ngang -> mã 8 số.
 * Tải dần: chỉ truy vấn cấp con khi người dùng mở cấp cha (cả năm có ~16.000 dòng).
 *
 * @property TariffYear $record
 */
class ManageYearCodes extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TariffYearResource::class;

    protected string $view = 'filament.tariff-years.tree';

    protected static ?string $navigationLabel = 'Mã HS';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    /** @var list<int> */
    public array $openSections = [];

    /** @var list<int> */
    public array $openChapters = [];

    /** @var list<int> */
    public array $openHeadings = [];

    public string $search = '';

    public ?int $highlightId = null;

    /** Dòng nhóm 4 số được tô sáng (link ?nhom= hoặc tìm theo mã 4 số). */
    public ?int $highlightHeadingId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        if ($code = request()->query('nhom')) {
            $heading = HsHeading::query()->whereBelongsTo($this->record)->where('code', $code)->first();
            $this->openPathTo($heading);
            $this->highlightHeadingId = $heading?->id;
        }
        // ?ma=87141020 (link từ khung chat tra cứu): mở đường tới dòng mã đó và tô sáng
        if (is_string($code = request()->query('ma')) && preg_match('/^\d{8}$/', $code)) {
            $this->search = $code;
            $this->find();
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Mã HS — biểu thuế '.$this->record->year;
    }

    public function toggleSection(int $id): void
    {
        $this->openSections = $this->toggle($this->openSections, $id);
    }

    public function toggleChapter(int $id): void
    {
        $this->openChapters = $this->toggle($this->openChapters, $id);
    }

    public function toggleHeading(int $id): void
    {
        $this->openHeadings = $this->toggle($this->openHeadings, $id);
    }

    public function collapseAll(): void
    {
        $this->openSections = $this->openChapters = $this->openHeadings = [];
        $this->highlightId = $this->highlightHeadingId = null;
    }

    /** Ô tìm: gõ mã (8714, 8714.10.20) hoặc chữ; mở thẳng tới kết quả đầu tiên. */
    public function find(): void
    {
        $search = trim($this->search);
        if ($search === '') {
            return;
        }
        $digits = preg_replace('/\D/', '', $search);
        $isCode = strlen($digits) >= 2 && strlen($digits) === strlen(preg_replace('/[\s.]/', '', $search));
        $nodes = HsNode::query()->whereBelongsTo($this->record)->orderBy('hs_heading_id')->orderBy('position');

        if ($isCode && strlen($digits) <= 4) {
            $heading = HsHeading::query()->whereBelongsTo($this->record)->where('code', 'like', "{$digits}%")->orderBy('code')->first();
            $this->openPathTo($heading);
            $this->highlightId = null;
            $this->highlightHeadingId = $heading?->id;
            $heading ?: $this->notFound();

            return;
        }

        $matches = $isCode ? $nodes->where('code', 'like', "{$digits}%") : $nodes->where('text', 'like', "%{$search}%");
        $total = (clone $matches)->count();
        $node = $matches->with('heading')->first();
        if (! $node) {
            $this->notFound();

            return;
        }
        $this->openPathTo($node->heading);
        $this->highlightId = $node->id;
        $this->highlightHeadingId = null;
        if ($total > 1) {
            Notification::make()->title("Có {$total} dòng khớp, đang mở dòng đầu tiên")
                ->body('Xem hết kết quả ở mục "Tra cứu mã".')->info()->send();
        }
    }

    public function taxAction(): Action
    {
        return HsNodeResource::taxAction()
            ->record(fn (array $arguments): ?HsNode => HsNode::query()->whereBelongsTo($this->record)->find($arguments['node'] ?? 0));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $sections = HsSection::query()->whereBelongsTo($this->record)->withCount('chapters')->orderBy('id')->get();
        $chapters = HsChapter::query()->whereIn('hs_section_id', $this->openSections)->withCount('headings')->orderBy('id')->get()->groupBy('hs_section_id');
        $headings = HsHeading::query()->whereIn('hs_chapter_id', $this->openChapters)->orderBy('code')->get()->groupBy('hs_chapter_id');
        $nodes = HsNode::query()->whereIn('hs_heading_id', $this->openHeadings)->orderBy('position')->get()->groupBy('hs_heading_id');

        return [
            'sections' => $sections,
            'chapters' => $chapters,
            'headings' => $headings,
            'nodeTrees' => $nodes->map(fn (Collection $rows): Collection => $rows->groupBy(fn (HsNode $node): string => (string) $node->parent_id)),
            'headingUrl' => fn (HsHeading $heading): string => HsHeadingResource::getUrl('view', ['record' => $heading]),
        ];
    }

    private function openPathTo(?HsHeading $heading): void
    {
        if ($heading === null) {
            return;
        }
        $chapter = $heading->chapter;
        $this->openSections = array_values(array_unique([...$this->openSections, $chapter->hs_section_id]));
        $this->openChapters = array_values(array_unique([...$this->openChapters, $chapter->id]));
        $this->openHeadings = array_values(array_unique([...$this->openHeadings, $heading->id]));
    }

    /** @param list<int> $ids */
    private function toggle(array $ids, int $id): array
    {
        return in_array($id, $ids, true) ? array_values(array_diff($ids, [$id])) : [...$ids, $id];
    }

    private function notFound(): void
    {
        Notification::make()->title('Không tìm thấy "'.$this->search.'"')->warning()->send();
    }
}
