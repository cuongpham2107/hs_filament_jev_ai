<?php

namespace App\Ai\HsLookup;

use Laravel\Ai\Contracts\Question;

/**
 * Câu hỏi gửi nguyên định dạng System One ({type, instructions, criteria}) qua laravel/ai.
 * Không dùng Laravel\Ai\Classification\Choice vì nó đòi tên lựa chọn là chuỗi, mà PHP tự đổi khoá "87", "8714",
 * "15" (chương, nhóm, chỉ số nút) thành số nguyên; đổi tên lựa chọn thì câu hỏi gửi Jev khác bản Dify.
 */
final readonly class WireQuestion implements Question
{
    /** @param array{type: string, instructions: string|array<string, mixed>, criteria?: array<int|string, mixed>} $question */
    public function __construct(public array $question) {}

    public function toArray(): array
    {
        return $this->question;
    }
}
