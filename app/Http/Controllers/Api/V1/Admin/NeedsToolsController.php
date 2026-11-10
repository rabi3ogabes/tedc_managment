<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\IndividualNeed;
use App\Models\NeedsRule;
use App\Models\NeedsSurvey;
use App\Models\SiteSetting;
use App\Models\Skill;
use App\Models\User;
use App\Services\IndividualNeedsService;
use App\Services\NeedsRuleEngine;
use App\Services\NotificationService;
use App\Services\PerformanceDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Individual needs, needs rules, performance data and instrument approval. */
class NeedsToolsController extends Controller
{
    public function __construct(private readonly IndividualNeedsService $needs, private readonly PerformanceDataService $performance) {}

    public function index(Request $request): JsonResponse
    {
        $q = $this->needs->query($this->user())->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('skill_id'), fn ($q, $v) => $q->where('skill_id', $v))->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('school_id'), fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('school_id', $v)))->orderByDesc('priority_score')->limit(500)->get();

        return response()->json(['data' => $q->map(fn ($n) => $this->present($n))->all(), 'auto_approve_days' => $this->needs->autoApproveDays()]);
    }

    public function decide(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid'], 'decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->needs->decide($this->user(), $d['ids'], $d['decision'], $d['note'] ?? null)]);
    }

    public function settings(Request $request): JsonResponse
    {
        $d = $request->validate(['auto_approve_days' => ['required', 'integer', 'between:0,90']]);
        SiteSetting::updateOrCreate(['key' => 'needs.auto_approve_days'], ['value' => ['days' => $d['auto_approve_days']], 'updated_by' => $this->user()->id]);

        return response()->json(['data' => ['auto_approve_days' => $this->needs->autoApproveDays()]]);
    }

    public function mine(): JsonResponse
    {
        $employee = $this->user()->employee;
        $rows = $employee ? IndividualNeed::with('skill:id,name_ar,name_en,code')->where('employee_id', $employee->id)->latest()->get() : collect();

        return response()->json(['data' => $rows->map(fn ($n) => $this->present($n))->all()]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => Skill::where('is_active', true)->orderBy('name_en')->get(['id', 'code', 'name_ar', 'name_en'])->all()]);
    }

    public function declare(Request $request): JsonResponse
    {
        $d = $request->validate(['skill_id' => ['required', 'uuid', 'exists:skills,id'], 'justification' => ['required', 'string', 'max:1000']]);
        $employee = $this->user()->employee;
        abort_unless($employee, 403);

        return response()->json(['data' => $this->present($this->needs->declare($employee, $d['skill_id'], $d['justification'])->load('skill'))], 201);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => NeedsRule::orderBy('sort_order')->get()->all()]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        return response()->json(['data' => NeedsRule::create($request->validate($this->ruleFields(true)))], 201);
    }

    public function updateRule(Request $request, NeedsRule $rule): JsonResponse
    {
        $rule->update($request->validate($this->ruleFields(false)));

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroyRule(NeedsRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function runRules(NeedsRuleEngine $engine): JsonResponse
    {
        return response()->json(['data' => $engine->run()]);
    }

    public function importAppraisals(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']]);

        return response()->json(['data' => $this->performance->importAppraisals($this->performance->readRows($request->file('file')->getRealPath()))]);
    }

    public function importObservations(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']]);

        return response()->json(['data' => $this->performance->importObservations($this->performance->readRows($request->file('file')->getRealPath()))]);
    }

    public function weak(Request $request): JsonResponse
    {
        [$years, $ratings] = $this->weakFilters($request);

        return response()->json(['data' => $this->performance->weak($this->user(), $years, $ratings)->all()]);
    }

    public function targetWeak(Request $request): JsonResponse
    {
        [$years, $ratings] = $this->weakFilters($request);
        $d = $request->validate(['skill_ids' => ['required', 'array', 'min:1', 'max:20'], 'skill_ids.*' => ['uuid', 'exists:skills,id'], 'required_level' => ['sometimes', 'integer', 'between:1,5']]);

        return response()->json(['data' => ['created' => $this->performance->target($this->user(), $years, $ratings, $d['skill_ids'], $d['required_level'] ?? 3)]]);
    }

    public function submitApproval(NeedsSurvey $needsSurvey, NotificationService $notifications): JsonResponse
    {
        $needsSurvey->update(['approval_status' => 'pending', 'approval_note' => null]);
        $ids = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'instruments.approve'))->pluck('id')->all();
        $ids && $notifications->broadcast($ids, 'instrument.awaiting_approval', ['ar' => 'أداة بانتظار اعتمادك', 'en' => 'An instrument awaits your approval'],
            ['ar' => "استبانة «{$needsSurvey->title}» بانتظار الاعتماد.", 'en' => "Survey \"{$needsSurvey->title}\" awaits approval."], ['detail_ar' => "استبانة «{$needsSurvey->title}» بانتظار الاعتماد.", 'detail_en' => "Survey \"{$needsSurvey->title}\" awaits approval."]);

        return response()->json(['data' => ['approval_status' => 'pending']]);
    }

    public function approve(NeedsSurvey $needsSurvey, NotificationService $notifications): JsonResponse
    {
        $needsSurvey->update(['approval_status' => 'approved', 'approved_by' => $this->user()->id, 'approved_at' => now(), 'approval_note' => null]);
        $this->tellOwner($needsSurvey, $notifications, 'approved', null);

        return response()->json(['data' => ['approval_status' => 'approved']]);
    }

    public function returnSurvey(Request $request, NeedsSurvey $needsSurvey, NotificationService $notifications): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:1000']])['note'];
        $needsSurvey->update(['approval_status' => 'returned', 'approval_note' => $note, 'approved_by' => null, 'approved_at' => null]);
        $this->tellOwner($needsSurvey, $notifications, 'returned', $note);

        return response()->json(['data' => ['approval_status' => 'returned']]);
    }

    private function tellOwner(NeedsSurvey $s, NotificationService $n, string $what, ?string $note): void
    {
        if ($s->created_by) {
            $ar = $what === 'approved' ? 'اعتُمدت' : 'أُعيدت للتعديل';
            $en = $what === 'approved' ? 'approved' : 'returned for changes';
            $n->send($s->created_by, 'instrument.decided', ['ar' => 'قرار بشأن الأداة', 'en' => 'Decision on your instrument'], ['ar' => "استبانة «{$s->title}» {$ar}".($note ? " — {$note}" : '').'.', 'en' => "Survey \"{$s->title}\" {$en}".($note ? " — {$note}" : '').'.'],
                ['detail_ar' => "استبانة «{$s->title}» {$ar}", 'detail_en' => "Survey \"{$s->title}\" {$en}"]);
        }
    }

    private function weakFilters(Request $request): array
    {
        $years = array_filter(array_map('intval', explode(',', (string) $request->input('years', now()->year - 1))));
        $ratings = array_values(array_intersect(explode(',', (string) $request->input('ratings', 'weak,acceptable')), array_keys(PerformanceDataService::RATINGS)));

        return [$years, $ratings ?: ['weak', 'acceptable']];
    }

    private function ruleFields(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return [
            'name_ar' => [$r, 'string', 'max:200'], 'name_en' => [$r, 'string', 'max:200'], 'trigger' => [$r, Rule::in(['new_hire', 'appraisal', 'observation', 'specialisation', 'stage', 'licence', 'test'])],
            'conditions' => ['sometimes', 'nullable', 'array'], 'action' => [$r, 'array'], 'action.skill_ids' => ['sometimes', 'array', 'max:30'], 'action.skill_ids.*' => ['uuid', 'exists:skills,id'],
            'action.required_level' => ['sometimes', 'integer', 'between:1,5'], 'action.priority_boost' => ['sometimes', 'numeric', 'between:0,5'], 'action.auto_approve' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function present(IndividualNeed $n): array
    {
        return [
            'id' => $n->id, 'employee_id' => $n->employee_id, 'employee' => $n->relationLoaded('employee') ? ['name' => $n->employee?->user?->displayName(), 'school' => $n->employee?->school?->translate('name'), 'employee_no' => $n->employee?->employee_no] : null,
            'skill' => $n->skill ? ['id' => $n->skill->id, 'name_ar' => $n->skill->name_ar, 'name_en' => $n->skill->name_en] : null, 'source' => $n->source, 'current_level' => $n->current_level, 'required_level' => $n->required_level, 'gap' => $n->gap,
            'priority_score' => $n->priority_score, 'explanation_ar' => $n->explanation_ar, 'explanation_en' => $n->explanation_en, 'status' => $n->status, 'manager_note' => $n->manager_note, 'decided_at' => $n->decided_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
