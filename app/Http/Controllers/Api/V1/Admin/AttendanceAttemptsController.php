<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Attendance attempts: every scan that did not record attendance, with the reason (wrong program, already present, expired code…). */
class AttendanceAttemptsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = AttendanceAttempt::with(['employee.user:id,name,name_ar', 'program:id,code,title_ar,title_en', 'session:id,title_ar,title_en,starts_at'])
            ->when($request->query('outcome'), fn ($q, $v) => $q->where('outcome', $v))
            ->when($request->query('program_id'), fn ($q, $v) => $q->where('program_id', $v))
            ->when($request->query('code'), fn ($q, $v) => $q->where('code', $v))
            ->latest('created_at')
            ->paginate($this->perPage($request));

        $summary = AttendanceAttempt::query()->where('created_at', '>=', now()->subDays(7))->selectRaw('code, count(*) as total')->groupBy('code')->orderByDesc('total')->pluck('total', 'code');

        return response()->json([
            'data' => $rows->getCollection()->map(fn (AttendanceAttempt $a) => [
                'id' => $a->id, 'outcome' => $a->outcome, 'code' => $a->code, 'message' => $a->message, 'at' => $a->created_at->toIso8601String(), 'device' => $a->device_info, 'ip' => $a->ip_address,
                'person' => $a->employee?->user?->displayName() ?? '—',
                'program' => $a->program ? ['id' => $a->program->id, 'code' => $a->program->code, 'title' => $a->program->translate('title')] : null,
                'session' => $a->session ? ['id' => $a->session->id, 'title' => $a->session->translate('title'), 'starts_at' => $a->session->starts_at->toIso8601String()] : null,
            ])->values(),
            'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage(), 'current_page' => $rows->currentPage()],
            'summary' => $summary,
        ]);
    }
}
