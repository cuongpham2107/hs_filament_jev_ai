<?php

use App\Enums\TariffYearStatus;
use App\Filament\Resources\HsHeadings\Pages\ViewHsHeading;
use App\Filament\Resources\HsSections\Pages\ViewHsSection;
use App\Filament\Resources\HsSections\RelationManagers\ChaptersRelationManager;
use App\Filament\Resources\TariffYears\Pages\ManageYearCodes;
use App\Filament\Resources\TariffYears\Pages\ManageYearCodeSearch;
use App\Jobs\BuildTariffYear;
use App\Models\HsHeading;
use App\Models\HsNode;
use App\Models\HsSection;
use App\Models\TariffYear;
use App\Models\User;
use App\Services\TariffImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Dữ liệu mẫu: 2 nhóm (0101, 8714) cắt ra từ data/2026 thật. */
function fixturePath(): string
{
    return base_path('tests/fixtures/data');
}

function importFixture(): TariffYear
{
    return app(TariffImporter::class)->import(2026, fixturePath());
}

it('nạp biểu thuế thành cây Phần -> Chương -> Nhóm -> nút -> mã', function () {
    $year = importFixture();
    $spokes = HsNode::query()->where('code', '87141020')->first();

    expect($year->status)->toBe(TariffYearStatus::Ready)
        ->and($year->counts)->toBe(['chuong' => 2, 'nhom' => 2, 'nut' => 51, 'ma' => 35])
        ->and($year->rules()->pluck('no')->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($spokes->heading->chapter->section->no)->toBe('XVII')
        ->and($spokes->heading->chapter->no)->toBe('87')
        ->and($spokes->parent->code)->toBe('871410')
        ->and($spokes->parent->level)->toBe(1)
        ->and($spokes->level)->toBe(2)
        ->and($spokes->subheading)->toBe('871410')
        ->and($spokes->path)->toBe('Của mô tô (kể cả xe gắn máy có bàn đạp (moped)) > Nan hoa và ốc bắt đầu nan hoa');
});

it('anh em cùng cấp có chung nút cha (Quy tắc 6)', function () {
    importFixture();
    $motorcycle = HsNode::query()->where('code', '871410')->first();

    expect($motorcycle->children->pluck('code')->all())
        ->toBe(['87141010', '87141020', '87141030', '87141040', '87141050', '87141060', '87141070', '87141090'])
        ->and($motorcycle->children->last()->label)->toStartWith('Loại khác (không phải: Yên xe');
});

it('nạp lại cùng năm thì thay dữ liệu cũ, không nhân đôi', function () {
    importFixture();
    importFixture();

    expect(TariffYear::query()->count())->toBe(1)
        ->and(HsNode::query()->tariffLines()->count())->toBe(35);
});

it('API dựng từ bảng đúng định dạng JSON hs_export cho workflow Dify', function () {
    importFixture();
    $dir = fixturePath().'/2026/hs_tree';

    expect($this->getJson('/api/hs/nhom/8714')->assertOk()->json())
        ->toBe(json_decode(file_get_contents("{$dir}/8714.json"), true))
        ->and($this->getJson('/api/hs/thue/87141020')->assertOk()->json())
        ->toBe(json_decode(file_get_contents("{$dir}/tax/87141020.json"), true))
        ->and($this->getJson('/api/hs/nhom/0101')->assertOk()->json())
        ->toBe(json_decode(file_get_contents("{$dir}/0101.json"), true));

    $this->getJson('/api/hs/quy-tac')->assertOk()->assertJsonPath('quy_tac', fn (string $rules) => str_contains($rules, 'QUY TẮC 1'));
});

it('API báo 404 khi không có nhóm hoặc năm', function () {
    importFixture();

    $this->getJson('/api/hs/nhom/9999')->assertNotFound();
    $this->getJson('/api/hs/nhom/8714?nam=2025')->assertNotFound();
});

it('API đòi token khi đã đặt HS_API_TOKEN', function () {
    importFixture();
    config(['hs.api_token' => 'bi-mat']);

    $this->getJson('/api/hs/nhom/8714')->assertUnauthorized();
    $this->withToken('bi-mat')->getJson('/api/hs/nhom/8714')->assertOk();
});

it('API dùng năm đang chọn khi không truyền ?nam', function () {
    importFixture();
    $older = TariffYear::query()->create(['year' => 2025, 'status' => TariffYearStatus::Ready]);

    $this->getJson('/api/hs/nhom/8714')->assertOk();
    $older->markActive();
    $this->getJson('/api/hs/nhom/8714')->assertNotFound();
    $this->getJson('/api/hs/nhom/8714?nam=2026')->assertOk();
});

it('job ghi trạng thái lỗi khi parse_tariff_excel.py thất bại', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Cần đúng 1 file .xlsx', exitCode: 1)]);

    expect(fn () => BuildTariffYear::dispatchSync(2027))->toThrow(RuntimeException::class);

    $year = TariffYear::query()->where('year', 2027)->first();
    expect($year->status)->toBe(TariffYearStatus::Failed)
        ->and($year->message)->toContain('Cần đúng 1 file .xlsx');
});

it('job nạp xong thì xoá file trung gian, giữ file Excel', function () {
    $dataPath = sys_get_temp_dir().'/hs-data-'.uniqid();
    File::copyDirectory(fixturePath(), $dataPath);
    File::put("{$dataPath}/2026/BIEU THUE 2026.xlsx", 'excel');
    config(['hs.data_path' => $dataPath]);
    Process::fake();  // file hs_export.py "sinh ra" đã có sẵn từ fixture

    BuildTariffYear::dispatchSync(2026);

    expect(TariffYear::query()->where('year', 2026)->first()->status)->toBe(TariffYearStatus::Ready)
        ->and(HsNode::query()->where('code', '87141020')->exists())->toBeTrue()
        ->and(collect(File::allFiles("{$dataPath}/2026"))->map->getFilename()->all())->toBe(['BIEU THUE 2026.xlsx']);
    File::deleteDirectory($dataPath);
});

it('trang tra cứu mã tìm được theo mã có dấu chấm và theo chữ', function () {
    $year = importFixture();
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageYearCodeSearch::class, ['record' => $year->getRouteKey()])
        ->searchTable('8714.10')
        ->assertCanSeeTableRecords(HsNode::query()->tariffLines()->where('code', 'like', '871410%')->get())
        ->assertCanNotSeeTableRecords(HsNode::query()->tariffLines()->where('code', 'like', '0101%')->get())
        ->searchTable('nan hoa')
        ->assertCanSeeTableRecords(HsNode::query()->where('code', '87141020')->get());
});

it('trang nhóm hiển thị cây phân nhóm', function () {
    importFixture();
    $this->actingAs(User::factory()->create());
    $heading = HsHeading::query()->where('code', '8714')->first();

    $this->get(ViewHsHeading::getUrl(['record' => $heading]))->assertOk()->assertSee('Bộ phận và phụ kiện của xe');
});

it('trang Phần liệt kê các chương của phần', function () {
    importFixture();
    $this->actingAs(User::factory()->create());
    $section = HsSection::query()->where('no', 'XVII')->first();

    $this->get(ViewHsSection::getUrl(['record' => $section]))->assertOk()->assertSee('Chú giải Phần');
    Livewire::test(ChaptersRelationManager::class, ['ownerRecord' => $section, 'pageClass' => ViewHsSection::class])
        ->assertCanSeeTableRecords($section->chapters);
});

it('trang Mã HS hiện cây Phần -> Chương -> Nhóm -> mã, mở dần từng cấp', function () {
    $year = importFixture();
    $this->actingAs(User::factory()->create());
    $section = HsSection::query()->where('no', 'XVII')->first();
    $chapter = $section->chapters()->first();
    $heading = HsHeading::query()->where('code', '8714')->first();

    Livewire::test(ManageYearCodes::class, ['record' => $year->getRouteKey()])
        ->assertSee('Phần XVII')
        ->assertDontSee('Chương 87')
        ->call('toggleSection', $section->id)
        ->assertSee('Chương 87')
        ->assertDontSee('87.14')
        ->call('toggleChapter', $chapter->id)
        ->assertSee('87.14')
        ->assertDontSee('Nan hoa và ốc bắt đầu nan hoa')
        ->call('toggleHeading', $heading->id)
        ->assertSee('8714.10.20')
        ->assertSee('Nan hoa và ốc bắt đầu nan hoa')
        ->call('toggleSection', $section->id)
        ->assertDontSee('Chương 87');
});

it('ô tìm trên cây mở thẳng tới mã, theo mã có dấu chấm hoặc theo chữ', function () {
    $year = importFixture();
    $this->actingAs(User::factory()->create());
    $spokes = HsNode::query()->where('code', '87141020')->first();

    Livewire::test(ManageYearCodes::class, ['record' => $year->getRouteKey()])
        ->set('search', '8714.10.20')->call('find')
        ->assertSet('highlightId', $spokes->id)
        ->assertSee('Nan hoa và ốc bắt đầu nan hoa');

    Livewire::test(ManageYearCodes::class, ['record' => $year->getRouteKey()])
        ->set('search', 'nan hoa')->call('find')
        ->assertSet('highlightId', $spokes->id)
        ->mountAction('tax', ['node' => $spokes->id])
        ->assertActionMounted('tax')
        ->assertMountedActionModalSee('Nan hoa và ốc bắt đầu nan hoa');
});

it('link ?ma= từ khung chat mở thẳng tới dòng mã và tô sáng', function () {
    $year = importFixture();
    $this->actingAs(User::factory()->create());
    $spokes = HsNode::query()->where('code', '87141020')->first();

    Livewire::withQueryParams(['ma' => '87141020'])
        ->test(ManageYearCodes::class, ['record' => $year->getRouteKey()])
        ->assertSet('highlightId', $spokes->id)
        ->assertSee('Nan hoa và ốc bắt đầu nan hoa');
});

it('link ?nhom= từ khung chat mở nhóm và tô sáng dòng nhóm', function () {
    $year = importFixture();
    $this->actingAs(User::factory()->create());
    $heading = HsHeading::query()->where('code', '8714')->first();

    Livewire::withQueryParams(['nhom' => '8714'])
        ->test(ManageYearCodes::class, ['record' => $year->getRouteKey()])
        ->assertSet('highlightHeadingId', $heading->id)
        ->assertSet('highlightId', null)
        ->assertSeeHtml('ring-warning-300');
});
