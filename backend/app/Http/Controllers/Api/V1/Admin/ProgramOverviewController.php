<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The one-line numbers behind each widget of the program page's widget view (participants, groups, sessions, tasks …): counts only,
 * in a handful of cheap queries, so the page opens at once and a widget fetches its full content only when it is opened.
 */
class ProgramOverviewController extends Controller
{
    public function __invoke(Program $program): JsonResponse
    {
        $id = $program->id;
        $count = fn (string $table, array $where = []) => (int) DB::table($table)->where('program_id', $id)->when($where, fn ($q) => $q->where($where))->count();
        $regs = Registration::where('program_id', $id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $groups = $program->groups()->get(['id', 'capacity']);
        $sessions = $program->sessions()->get(['starts_at', 'status']);
        $next = $sessions->where('starts_at', '>=', now())->sortBy('starts_at')->first();
        $held = Registration::where('program_id', $id)->whereIn('status', Registration::SEAT_HOLDING);
        $impact = Registration::where('program_id', $id)->whereNotNull('impact_score')->avg('impact_score');

        return response()->json(['data' => [
            'participants' => ['total' => (int) $regs->sum(), 'approved' => (int) ($regs['approved'] ?? 0), 'pending' => (int) (($regs['pending'] ?? 0) + ($regs['pending_manager'] ?? 0)), 'waitlisted' => (int) ($regs['waitlisted'] ?? 0)],
            'groups' => ['count' => $groups->count(), 'capacity' => (int) $groups->sum('capacity'), 'seats_taken' => (int) $held->count()],
            'structure' => ['units' => $count('program_units'), 'sub_programs' => (int) Program::where('parent_id', $id)->count()],
            'admission' => ['capacity' => (int) ($groups->sum('capacity') ?: $program->capacity), 'seats_taken' => (int) $held->count(), 'waitlisted' => (int) ($regs['waitlisted'] ?? 0)],
            'assessments' => ['count' => $count('assessments'), 'published' => $count('assessments', ['status' => 'published'])],
            'passing' => ['passed' => (int) Registration::where('program_id', $id)->whereIn('pass_status', ['passed', 'exempted'])->count(), 'total' => (int) $regs->sum()],
            'evaluation' => ['assignments' => $count('evaluation_assignments'), 'interviews' => $count('evaluation_interviews')],
            'sessions' => ['count' => $sessions->count(), 'completed' => $sessions->where('status', 'completed')->count(), 'next' => $next?->starts_at?->toIso8601String()],
            'course' => ['modules' => $count('course_modules'), 'lessons' => $count('course_lessons')],
            'rules' => ['count' => $count('eligibility_rules')],
            'tasks' => ['count' => $count('tasks')],
            'materials' => ['count' => $count('materials')],
            'survey' => ['mode' => $program->survey_mode, 'auto_hours' => $program->survey_auto_hours],
            'certificates' => ['issued' => $count('certificates', ['status' => 'valid'])],
            'impact' => ['average' => $impact !== null ? round((float) $impact, 1) : null],
            'staff' => ['grants' => $count('program_grants')],
        ]]);
    }
}
