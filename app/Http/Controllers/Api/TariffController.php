<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HsHeading;
use App\Models\HsNode;
use App\Models\TariffYear;
use App\Services\TariffDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API cho workflow Dify, dựng từ các bảng cây biểu thuế. Chọn năm bằng ?nam=2026; mặc định là năm đang dùng.
 */
class TariffController extends Controller
{
    /** Không escape tiếng Việt: node HTTP của Dify giới hạn 1 MB/phản hồi, nhóm 8703 đã ~0,5 MB. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(private TariffDocuments $documents) {}

    public function catalog(Request $request): JsonResponse
    {
        $year = $this->year($request);

        return $year ? $this->ok($this->documents->catalog($year)) : $this->notFound();
    }

    public function heading(Request $request, string $code): JsonResponse
    {
        $heading = HsHeading::query()->whereBelongsTo($this->year($request) ?? new TariffYear)->where('code', $code)->first();

        return $heading ? $this->ok($this->documents->heading($heading)) : $this->notFound();
    }

    public function tax(Request $request, string $code): JsonResponse
    {
        $line = HsNode::query()->tariffLines()->whereBelongsTo($this->year($request) ?? new TariffYear)->where('code', $code)->first();

        return $line ? $this->ok($this->documents->tax($line)) : $this->notFound();
    }

    public function rules(Request $request): JsonResponse
    {
        $year = $this->year($request);

        return $year ? $this->ok(['quy_tac' => $this->documents->rules($year)]) : $this->notFound();
    }

    private function year(Request $request): ?TariffYear
    {
        return TariffYear::current($request->integer('nam') ?: null);
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json($data, options: self::JSON_FLAGS);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Không có dữ liệu cho năm hoặc mã này'], 404, options: self::JSON_FLAGS);
    }
}
