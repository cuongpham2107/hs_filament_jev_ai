<?php

use App\Ai\HsLookup\LookupResult;

/** Một nhóm, hai mã 8 số cùng cấp: nhánh đầu được $pFirst, phần còn lại Jev dồn vào CHUA_DU_THONG_TIN. */
function readOneBranch(float $pFirst): array
{
    $trees = [[
        'code' => '9999',
        'name' => 'Nhóm thử',
        'nodes' => [
            ['code' => '99990010', 'label' => 'Loại A', 'parent' => null],
            ['code' => '99990090', 'label' => 'Loại khác', 'parent' => null],
        ],
        'codes' => ['99990010' => ['path' => 'Loại A'], '99990090' => ['path' => 'Loại khác']],
    ]];
    $answers = [
        'nhom' => ['probabilities' => ['9999' => 1.0]],
        'co_nhom_phu_hop' => ['noul' => 0.9],
        '9999_r' => ['probabilities' => ['0' => $pFirst, '1' => 0.05, 'CHUA_DU_THONG_TIN' => 0.95 - $pFirst]],
    ];

    return (new LookupResult)->read($answers, $trees);
}

it('chốt mã khi xác suất mã và cách biệt đủ, dù một bước dưới 70%', function () {
    $read = readOneBranch(0.67);

    expect($read['trang_thai'])->toBe('ok')
        ->and($read['ma_hs'])->toBe('99990010');
});

it('vẫn cần bổ sung khi xác suất mã dưới 55%', function () {
    expect(readOneBranch(0.5)['trang_thai'])->toBe('can_bo_sung');
});
