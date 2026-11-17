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

/**
 * Cây 4 tầng, mỗi tầng hai nhánh; mã cuối là 99990031. $probs là xác suất nhánh đúng ở từng tầng.
 *
 * @param  list<float>  $probs
 */
function readDeepTree(array $probs): array
{
    $nodes = [
        ['code' => null, 'label' => 'Nhánh 1', 'parent' => null],
        ['code' => null, 'label' => 'Nhánh 2', 'parent' => null],
        ['code' => null, 'label' => 'Nhánh 1.1', 'parent' => 0],
        ['code' => null, 'label' => 'Nhánh 1.2', 'parent' => 0],
        ['code' => null, 'label' => 'Nhánh 1.1.1', 'parent' => 2],
        ['code' => null, 'label' => 'Nhánh 1.1.2', 'parent' => 2],
        ['code' => '99990031', 'label' => 'Nhánh 1.1.1.1', 'parent' => 4],
        ['code' => '99990032', 'label' => 'Nhánh 1.1.1.2', 'parent' => 4],
    ];
    $answers = [
        'nhom' => ['probabilities' => ['9999' => 1.0]],
        'co_nhom_phu_hop' => ['noul' => 0.9],
        '9999_r' => ['probabilities' => ['0' => $probs[0], '1' => 1 - $probs[0]]],
        '9999_0' => ['probabilities' => ['2' => $probs[1], '3' => 1 - $probs[1]]],
        '9999_2' => ['probabilities' => ['4' => $probs[2], '5' => 1 - $probs[2]]],
        '9999_4' => ['probabilities' => ['6' => $probs[3], '7' => 1 - $probs[3]]],
    ];
    $trees = [['code' => '9999', 'name' => 'Nhóm thử', 'nodes' => $nodes,
        'codes' => ['99990031' => ['path' => 'Nhánh 1 › 1.1 › 1.1.1 › 1.1.1.1']]]];

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

it('chốt mã khi cả 4 tầng đều 85%, dù tích chỉ 52%', function () {
    $read = readDeepTree([0.85, 0.85, 0.85, 0.85]);

    expect($read['trang_thai'])->toBe('ok')
        ->and($read['ma_hs'])->toBe('99990031')
        ->and($read['do_tin_cay'])->toBe(0.85);
});

it('cần bổ sung khi có một tầng dưới 60%', function () {
    expect(readDeepTree([0.85, 0.85, 0.55, 0.85])['trang_thai'])->toBe('can_bo_sung');
});

it('cần bổ sung khi nút yếu nhất sát nhánh thứ hai', function () {
    expect(readDeepTree([0.62, 0.62, 0.62, 0.52])['trang_thai'])->toBe('can_bo_sung');
});

it('hỏi theo tiêu chí của các nhánh, không đọc lại nhãn biểu thuế', function () {
    $nodes = [
        ['code' => null, 'label' => 'Ghế khác', 'parent' => null],
        ['code' => null, 'label' => 'Ghế khác, có khung bằng gỗ', 'parent' => null],
        ['code' => null, 'label' => 'Ghế khác, có khung bằng kim loại', 'parent' => null],
        ['code' => null, 'label' => 'Ghế quay có điều chỉnh độ cao', 'parent' => null],
        ['code' => '99990010', 'label' => 'Ghế khác', 'parent' => 0],
        ['code' => '99990011', 'label' => 'Ghế khác, có khung bằng gỗ', 'parent' => 1],
        ['code' => '99990012', 'label' => 'Ghế khác, có khung bằng kim loại', 'parent' => 2],
        ['code' => '99990013', 'label' => 'Ghế quay có điều chỉnh độ cao', 'parent' => 3],
    ];
    $answers = [
        'nhom' => ['probabilities' => ['9999' => 1.0]],
        'co_nhom_phu_hop' => ['noul' => 0.9],
        '9999_r' => ['probabilities' => ['0' => 0.55, '1' => 0.20, '2' => 0.15, '3' => 0.05, 'CHUA_DU_THONG_TIN' => 0.05]],
    ];
    $trees = [['code' => '9999', 'name' => 'Nhóm thử', 'nodes' => $nodes, 'codes' => []]];

    $read = (new LookupResult)->read($answers, $trees);

    expect($read['trang_thai'])->toBe('can_bo_sung')
        ->and($read['can_lam_ro'])->toContain('khung bằng gỗ')
        ->and($read['can_lam_ro'])->toContain('Ghế quay')
        ->and($read['candidates'])->toHaveCount(4)
        ->and($read['candidates'][0][0])->toBe('99990010');
});

/** Hai nhóm, mỗi nhóm một mã 8 số; $noul là câu trả lời 'có nhóm phù hợp' của Jev. */
function readTwoHeadings(float $noul, float $pFirst = 0.7): array
{
    $tree = fn (string $code): array => [
        'code' => $code,
        'name' => "Nhóm {$code}",
        'nodes' => [['code' => "{$code}0010", 'label' => 'Loại A', 'parent' => null]],
        'codes' => ["{$code}0010" => ['path' => 'Loại A']],
    ];
    $answers = [
        'nhom' => ['probabilities' => ['9999' => $pFirst, '8888' => 1 - $pFirst]],
        'co_nhom_phu_hop' => ['noul' => $noul],
    ];

    return (new LookupResult)->read($answers, [$tree('9999'), $tree('8888')]);
}

it('dải giữa của noul: chưa rõ có nhóm nào khớp thì hỏi lại chuyện chọn nhóm', function () {
    $read = readTwoHeadings(0.5);

    expect($read['trang_thai'])->toBe('can_bo_sung')
        ->and($read['can_lam_ro'])->toContain('Hàng thuộc nhóm nào')
        ->and($read['can_lam_ro'])->toContain('99.99');
});

it('noul rõ không khớp thì bỏ mã, trả KHONG_PHU_HOP', function () {
    expect(readTwoHeadings(0.05)['ma_hs'])->toBe('KHONG_PHU_HOP');
});

it('noul rõ khớp và xác suất nhóm đủ thì chốt mã', function () {
    expect(readTwoHeadings(0.95)['trang_thai'])->toBe('ok');
});
