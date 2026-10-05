<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\ImpactSurvey;
use App\Models\Program;
use App\Models\Registration;
use App\Models\SatisfactionAlert;
use App\Models\SupervisorEvaluation;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\EvaluationSettings;
use App\Services\FileStorage;
use App\Services\NeedsSurveys\SurveyAnalyzer;
use App\Services\SatisfactionAlertService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The evaluation centre of a group: the board of every instrument, assigning, reminding, results with evidence. */
class GroupEvaluationController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluations, private readonly SatisfactionAlertService $alerts, private readonly EvaluationSettings $settings) {}

    public function board(Program $program, Request $request): JsonResponse
    {
        $group = $this->group($program, $request);
        $this->evaluations->ensureDefaults();
        $regs = Registration::where('program_id', $program->id)->when($group, fn ($q) => $q->where('training_group_id', $group->id))->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
        $ids = $regs->pluck('id');
        $sat = $this->alerts->stats($program, $group);
        $impact = ImpactSurvey::whereIn('registration_id', $ids)->get();
        $mgr = SupervisorEvaluation::whereIn('registration_id', $ids)->count();
        $mgrAsked = EvaluationAssignment::where('program_id', $program->id)->where('respondent_type', 'manager')->when($group, fn ($q) => $q->where('group_id', $group->id))->count();

        $assignments = EvaluationAssignment::with('form:id,kind,title_ar,title_en', 'respondent:id,name,name_ar')->where('program_id', $program->id)->when($group, fn ($q) => $q->where('group_id', $group->id))->where('respondent_type', '!=', 'manager')->latest()->limit(300)->get();
        $instruments = [
            ['key' => 'satisfaction', 'expected' => $sat['expected'], 'responses' => $sat['responses'], 'rate' => $sat['response_rate']],
            ['key' => 'impact_trainee', 'expected' => $impact->count(), 'responses' => $impact->where('status', 'completed')->count(), 'rate' => $impact->count() ? round($impact->where('status', 'completed')->count() / $impact->count() * 100, 1) : 0],
            ['key' => 'impact_manager', 'expected' => max($mgrAsked, $mgr), 'responses' => $mgr, 'rate' => max($mgrAsked, $mgr) ? round($mgr / max($mgrAsked, $mgr) * 100, 1) : 0],
        ];
        foreach (['trainer_reflection', 'planning_evaluation', 'supervisor_feedback', 'specialist_feedback', 'custom'] as $kind) {
            $rows = $assignments->filter(fn ($a) => $a->form->kind === $kind);
            if ($rows->isNotEmpty()) {
                $instruments[] = ['key' => $kind, 'expected' => $rows->count(), 'responses' => $rows->where('status', 'submitted')->count(), 'rate' => round($rows->where('status', 'submitted')->count() / $rows->count() * 100, 1)];
            }
        }

        return response()->json(['data' => ['instruments' => $instruments, 'satisfaction' => $sat, 'alert' => SatisfactionAlert::where('program_id', $program->id)->where('group_id', $group?->id)->first(), 'rule' => $this->settings->get('alerts'),
            'assignments' => $assignments->map(fn ($a) => ['id' => $a->id, 'kind' => $a->form->kind, 'title_ar' => $a->form->title_ar, 'title_en' => $a->form->title_en, 'respondent' => $a->respondent?->displayName(), 'respondent_type' => $a->respondent_type, 'status' => $a->status, 'due_at' => $a->due_at?->toIso8601String(), 'reminded_at' => $a->reminded_at?->toIso8601String()])->values(),
            'forms' => EvaluationForm::where('is_system', false)->where('approval_status', 'approved')->get(['id', 'kind', 'title_ar', 'title_en'])]]);
    }

    /** Hands a form to chosen people (the planning head assigns the planning-team evaluation here). */
    public function assign(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate(['form_id' => ['required', 'uuid', 'exists:evaluation_forms,id'], 'group_id' => ['required', 'uuid', 'exists:training_groups,id'], 'user_ids' => ['required', 'array', 'min:1', 'max:100'], 'user_ids.*' => ['uuid'], 'due_at' => ['nullable', 'date']]);
        $group = TrainingGroup::where('program_id', $program->id)->findOrFail($d['group_id']);
        $form = EvaluationForm::where('approval_status', 'approved')->where('is_system', false)->findOrFail($d['form_id']);
        $n = $this->evaluations->assignManual($form, $group, $d['user_ids'], isset($d['due_at']) ? Carbon::parse($d['due_at']) : null, $this->user());

        return response()->json(['data' => ['assigned' => $n]]);
    }

    public function remind(EvaluationAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->status === 'pending', 422);
        $this->evaluations->remind($assignment);

        return response()->json(['data' => ['reminded' => true]]);
    }

    /** Who can be asked: the people holding the planning-respondent permission for the planning evaluation, any active staff otherwise. */
    public function assignable(Request $request): JsonResponse
    {
        $planning = $request->query('kind') === 'planning_evaluation';
        $users = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('slug', '!=', 'employee'))->when($planning, fn ($q) => $q->whereHas('roles.permissions', fn ($p) => $p->where('slug', 'evaluations.respond_planning')))->orderBy('name')->limit(200)->get(['id', 'name', 'name_ar']);

        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->displayName()])->values()]);
    }

    /** Per-question results of one instrument, the open-text answers and the evidence. */
    public function results(Program $program, string $kind, Request $request, SurveyAnalyzer $analyzer): JsonResponse
    {
        $group = $this->group($program, $request);
        if ($kind === 'satisfaction') {
            return response()->json(['data' => $this->satisfaction($program, $group)]);
        }
        $form = EvaluationForm::where('kind', $kind)->where('is_system', false)->orderByDesc('is_default')->first();
        abort_unless($form, 404);
        $responses = EvaluationResponse::with('assignment.respondent:id,name,name_ar')->whereHas('assignment', fn ($q) => $q->where('program_id', $program->id)->where('form_id', $form->id)->when($group, fn ($w) => $w->where('group_id', $group->id)))->get();
        $anonymous = (bool) ($form->settings['anonymous'] ?? false);
        $stats = $analyzer->questionStats($form->questions, $responses);
        $evidence = $responses->flatMap(fn ($r) => collect($r->evidence ?? [])->flatMap(fn ($items, $qid) => collect($items)->map(fn ($item, $i) => ['response_id' => $r->id, 'question_id' => $qid, 'index' => $i, 'type' => $item['type'], 'name' => $item['name'] ?? $item['url'] ?? '', 'by' => $anonymous ? null : $r->assignment->respondent?->displayName()])))->values();

        return response()->json(['data' => ['kind' => $kind, 'form' => ['id' => $form->id, 'title_ar' => $form->title_ar, 'title_en' => $form->title_en], 'responses' => $responses->count(), 'average_score' => $responses->whereNotNull('score')->isNotEmpty() ? round((float) $responses->whereNotNull('score')->avg('score'), 1) : null, 'questions' => $stats, 'evidence' => $evidence]]);
    }

    public function evidenceUrl(EvaluationResponse $response, string $question, int $index, FileStorage $storage): JsonResponse
    {
        $item = ($response->evidence[$question] ?? [])[$index] ?? abort(404);

        return response()->json(['data' => ['url' => $item['type'] === 'link' ? $item['url'] : $storage->temporaryUrl('evidence', $item['path']), 'name' => $item['name'] ?? $item['url']]]);
    }

    public function ranking(): JsonResponse
    {
        return response()->json(['data' => $this->alerts->ranking()]);
    }

    private function satisfaction(Program $program, ?TrainingGroup $group): array
    {
        $min = (int) $this->settings->get('anonymous_min_responses', 3);
        $s = $this->alerts->stats($program, $group);
        if ($s['responses'] > 0 && $s['responses'] < $min) {
            return ['kind' => 'satisfaction', 'responses' => $s['responses'], 'hidden_until' => $min, 'questions' => []];
        }
        $evals = Evaluation::whereHas('registration', fn ($q) => $q->where('program_id', $program->id)->when($group, fn ($w) => $w->where('training_group_id', $group->id)))->get();
        $criteria = $evals->flatMap(fn ($e) => collect($e->ratings))->groupBy(fn ($v, $k) => $k)->map(fn ($v, $k) => ['id' => $k, 'type' => 'rating', 'title' => $k, 'answered' => $v->count(), 'average' => round((float) $v->avg(), 2), 'scale' => ['min' => 1, 'max' => 5],
            'distribution' => collect(range(1, 5))->map(fn ($p) => ['label' => (string) $p, 'value' => $v->filter(fn ($x) => (int) $x === $p)->count()])->all()])->values();

        return ['kind' => 'satisfaction', 'responses' => $s['responses'], 'average_score' => $s['average'], 'response_rate' => $s['response_rate'], 'questions' => $criteria, 'comments' => $evals->pluck('comments')->filter()->values()->take(50)];
    }

    private function group(Program $program, Request $request): ?TrainingGroup
    {
        $id = $request->query('group');

        return $id ? TrainingGroup::where('program_id', $program->id)->findOrFail($id) : null;
    }
}
