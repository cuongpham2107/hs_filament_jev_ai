<?php

use App\Ai\HsLookup\LookupQuestions;

it('hỏi theo phần khác nhau của nhãn thay vì đọc lại nhãn biểu thuế', function () {
    expect(LookupQuestions::ask([
        'Ghế khác',
        'Ghế khác, có khung bằng gỗ',
        'Ghế khác, có khung bằng kim loại',
    ]))->toBe('Hàng có khung bằng gỗ, khung bằng kim loại, hay không?');
});

it('hỏi thẳng khi nhánh còn lại là "Loại khác"', function () {
    expect(LookupQuestions::ask(['Tấm mạch in đã lắp ráp', 'Loại khác']))
        ->toBe('Hàng có phải là «Tấm mạch in đã lắp ráp» không?');
});

it('nêu nhánh khác nhóm ở cuối câu hỏi', function () {
    expect(LookupQuestions::ask([
        'Ghế khác',
        'Ghế khác, có khung bằng gỗ',
        'Ghế quay có điều chỉnh độ cao',
    ]))->toBe('Hàng có khung bằng gỗ, hay không (hoặc: Ghế quay có điều chỉnh độ cao)?');
});

it('không đặt câu hỏi khi không đủ nhánh', function () {
    expect(LookupQuestions::ask([]))->toBe('')
        ->and(LookupQuestions::ask(['Ghế khác']))->toBe('');
});
