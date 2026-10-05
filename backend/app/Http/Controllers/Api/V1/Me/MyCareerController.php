<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\Employee;
use App\Models\EmployeePathProgress;
use App\Models\KnowledgeTransfer;
use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\ProfessionalLicence;
use App\Services\AnnualHoursService;
use App\Services\CareerPathEngine;
use App\Services\EvaluationService;
use App\Services\KnowledgeTransferService;
use App\Services\PdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The employee's paths, licences, professional development and knowledge transfers. */
class MyCareerController extends MeController
{
    public function __construct(private readonly PdService $pd, private readonly AnnualHoursService $hours, private readonly KnowledgeTransferService $kt, private readonly CareerPathEngine $paths, private readonly EvaluationService $evaluations) {}

    public function paths(): JsonResponse
    {
        $e = $this->employee();
        $this->paths->evaluate($e);
        $rows = EmployeePathProgress::with('path.levels')->where('employee_id', $e->id)->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->path($p))->values()]);
    }

    public function showPath(string $path): JsonResponse
    {
        $row = EmployeePathProgress::with('path.levels')->where('employee_id', $this->employee()->id)->where('path_id', $path)->firstOrFail();

        return response()->json(['data' => $this->path($row)]);
    }

    private function path(EmployeePathProgress $progress): array
    {
        return ['path_id' => $progress->path_id, 'type' => $progress->path->type, 'title_ar' => $progress->path->title_ar, 'title_en' => $progress->path->title_en, 'levels' => $progress->path->levels->map(fn ($l) => ['level_no' => $l->level_no, 'title_ar' => $l->title_ar, 'title_en' => $l->title_en])->values(),
            'current_level_no' => $progress->current_level_no, 'target_level_no' => $progress->target_level_no, 'status' => $progress->status, 'explanation' => $progress->explanation ?? [], 'evaluated_at' => $progress->evaluated_at?->toIso8601String()];
    }

    public function licences(): JsonResponse
    {
        return response()->json(['data' => ProfessionalLicence::with('path:id,title_ar,title_en')->where('employee_id', $this->employee()->id)->orderByDesc('issued_at')->get()->all()]);
    }

    public function hoursSummary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->hours->summary($this->employee(), $request->integer('year') ?: null)]);
    }

    public function types(): JsonResponse
    {
        $this->pd->ensureTypes();

        return response()->json(['data' => PdActivityType::where('is_active', true)->orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en', 'hour_rules', 'evidence_required'])]);
    }

    public function activities(): JsonResponse
    {
        return response()->json(['data' => PdActivity::with('type:id,code,name_ar,name_en')->where('employee_id', $this->employee()->id)->latest()->get()->all()]);
    }

    public function saveActivity(Request $request, ?PdActivity $activity = null): JsonResponse
    {
        $e = $this->employee();
        abort_if($activity && $activity->employee_id !== $e->id, 404);
        $d = $request->validate(['type_id' => ['required', 'uuid', 'exists:pd_activity_types,id'], 'title' => ['required', 'string', 'max:255'], 'provider' => ['nullable', 'string', 'max:255'], 'domain' => ['nullable', 'string', 'max:60'], 'skill_ids' => ['nullable', 'array'], 'skill_ids.*' => ['uuid'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'duration_hours' => ['required', 'numeric', 'min:0.5', 'max:500'], 'participation_level' => ['required', Rule::in(PdService::LEVELS)], 'location' => ['nullable', 'string', 'max:255'], 'recognition_request' => ['sometimes', 'boolean'],
            'evidence' => ['sometimes', 'array', 'max:5'], 'evidence.*' => ['nullable']]);
        $files = array_merge($request->file('evidence', []) ?: [], array_filter((array) $request->input('evidence', []), 'is_string'));
        $existing = $activity?->evidence ?? [];
        $data = collect($d)->except('evidence')->all();
        $row = $this->pd->save($e, $data, $activity);
        if ($files) {
            $row->update(['evidence' => array_merge($existing, $this->evaluations->evidenceItems($files, "pd/{$row->id}", 5 - count($existing), 'evidence'))]);
        }

        return response()->json(['data' => $row->fresh('type')], $activity ? 200 : 201);
    }

    /** What the hours would be, before saving. */
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['type_id' => ['required', 'uuid', 'exists:pd_activity_types,id'], 'participation_level' => ['required', Rule::in(PdService::LEVELS)], 'duration_hours' => ['required', 'numeric', 'min:0', 'max:500']]);

        return response()->json(['data' => ['hours' => $this->pd->compute(PdActivityType::findOrFail($d['type_id']), $d['participation_level'], (float) $d['duration_hours'])]]);
    }

    public function submitActivity(PdActivity $activity): JsonResponse
    {
        abort_unless($activity->employee_id === $this->employee()->id, 404);

        return response()->json(['data' => $this->pd->submit($activity)]);
    }

    /** The manager's side: the team's activities waiting for a decision. */
    public function teamActivities(): JsonResponse
    {
        $rows = PdActivity::with('employee.user:id,name,name_ar', 'type:id,name_ar,name_en')->where('manager_id', $this->user()->id)->where('status', 'pending_manager')->latest()->get();

        return response()->json(['data' => $rows->map(fn ($a) => $a->toArray() + ['employee_name' => $a->employee->user?->displayName()])->all()]);
    }

    public function transfers(): JsonResponse
    {
        return response()->json(['data' => KnowledgeTransfer::with('registration.program:id,code,title_ar,title_en')->where('employee_id', $this->employee()->id)->latest()->get()->map(fn ($k) => $k->toArray() + ['program' => $k->registration->program->translate('title')])->all()]);
    }

    public function submitTransfer(Request $request, KnowledgeTransfer $transfer): JsonResponse
    {
        abort_unless($transfer->employee_id === $this->employee()->id, 404);
        $d = $request->validate(['delivered_on' => ['required', 'date', 'before_or_equal:today'], 'hours' => ['required', 'numeric', 'min:0.5', 'max:100'], 'method' => ['required', Rule::in(['workshop', 'meeting', 'coaching', 'online'])],
            'beneficiaries' => ['required', 'array', 'max:500'], 'beneficiaries.*.employee_id' => ['nullable', 'uuid', 'exists:employees,id'], 'beneficiaries.*.name' => ['nullable', 'string', 'max:200'], 'evidence' => ['sometimes', 'array', 'max:8'], 'evidence.*' => ['nullable']]);
        $files = array_merge($request->file('evidence', []) ?: [], array_filter((array) $request->input('evidence', []), 'is_string'));

        return response()->json(['data' => $this->kt->submit($transfer, collect($d)->except('evidence')->all(), $files)]);
    }

    /** Colleagues to pick as beneficiaries. */
    public function colleagues(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        abort_if(mb_strlen($q) < 2, 422);
        $rows = Employee::with('user:id,name,name_ar', 'school:id,name_ar,name_en')->where('id', '!=', $this->employee()->id)->whereHas('user', fn ($u) => $u->whereLike('name', "%{$q}%")->orWhereLike('name_ar', "%{$q}%"))->limit(15)->get();

        return response()->json(['data' => $rows->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->displayName(), 'school' => $e->school?->translate('name')])->values()]);
    }
}
