<?php

namespace App\Http\Controllers;

use App\Ai\HsLookup\HsLookup;
use App\Models\TraCuuLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Danh sách và chi tiết các cuộc hội thoại tra cứu HS (lấy từ bảng tra_cuu_logs của người dùng hiện tại).
 */
class TraCuuConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = TraCuuLog::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'conversation_id', 'message', 'status', 'ma_hs', 'do_tin_cay', 'created_at']);

        $grouped = [];
        foreach ($logs as $log) {
            $key = $log->conversation_id ?: 'single-'.$log->id;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'id' => $key,
                    'title' => $this->excerpt($log->message),
                    'ma_hs' => $log->formattedCode(),
                    'status' => $log->status,
                    'turns_count' => 1,
                    'updated_at' => $log->created_at?->toIso8601String(),
                ];
            } else {
                $grouped[$key]['turns_count']++;
                $grouped[$key]['title'] = $this->excerpt($log->message); // lượt đầu tiên làm tiêu đề
                if ($grouped[$key]['ma_hs'] === null && $log->ma_hs !== null) {
                    $grouped[$key]['ma_hs'] = $log->formattedCode();
                }
            }
        }

        return response()->json([
            'conversations' => array_slice(array_values($grouped), 0, 40),
        ]);
    }

    public function show(Request $request, string $conversationId): JsonResponse
    {
        $logs = $this->queryForUser($request, $conversationId)
            ->orderBy('id')
            ->get();

        abort_if($logs->isEmpty(), 404);

        $doneSteps = array_fill_keys(array_keys(HsLookup::STEPS), 'done');

        $turns = $logs->map(fn (TraCuuLog $log): array => [
            'id' => 'log-'.$log->id,
            'message' => $log->message,
            'status' => $log->error ? 'error' : 'done',
            'steps' => $log->error ? [] : $doneSteps,
            'reasoning' => $log->can_cu ? array_values(array_filter(explode("\n", $log->can_cu))) : [],
            'durationSeconds' => $log->duration_ms ? round($log->duration_ms / 1000, 1) : null,
            'result' => $log->the_ket_qua ? [
                'status' => $log->status === 'can_bo_sung' ? 'can_bo_sung' : 'ok',
                'markdown' => $log->the_ket_qua,
                'code' => $log->ma_hs,
                'confidence' => $log->do_tin_cay ?? 0,
            ] : null,
            'error' => $log->error,
            'conversationId' => $log->conversation_id,
            'isFollowUp' => (bool) $log->is_follow_up,
        ])->values();

        return response()->json(['turns' => $turns]);
    }

    public function destroy(Request $request, string $conversationId): JsonResponse
    {
        $deleted = $this->queryForUser($request, $conversationId)->delete();
        abort_if($deleted === 0, 404);

        return response()->json(['deleted' => true]);
    }

    private function queryForUser(Request $request, string $conversationId)
    {
        $query = TraCuuLog::query()->where('user_id', $request->user()->getKey());

        if (str_starts_with($conversationId, 'single-')) {
            return $query->where('id', (int) substr($conversationId, 7));
        }

        return $query->where('conversation_id', $conversationId);
    }

    private function excerpt(string $message): string
    {
        $firstLine = trim(strtok($message, "\r\n") ?: $message);

        return mb_strlen($firstLine) > 60 ? mb_substr($firstLine, 0, 57).'…' : $firstLine;
    }
}
