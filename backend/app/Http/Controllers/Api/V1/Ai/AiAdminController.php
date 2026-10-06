<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\Adaptive\RemedialBuilder;
use App\Ai\AiGuard;
use App\Ai\AiPolicy;
use App\Ai\Feedback\SmartFeedback;
use App\Ai\Forecast\ForecastService;
use App\Ai\Forecast\RiskService;
use App\Ai\Rag\Indexer;
use App\Ai\Recommend\HybridRecommender;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AdaptiveRule;
use App\Models\AiFeedbackDraft;
use App\Models\AiLog;
use App\Models\AssessmentAttempt;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Embedding;
use App\Models\Forecast;
use App\Models\Program;
use App\Models\RiskFlag;
use App\Models\Skill;
use App\Models\TrainingPlan;
use App\Services\Ai\AiModelSettings;
use App\Services\AnnualPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Settings, logs and controls of the AI features, the grader's drafts, adaptive rules, forecasts and risks. */
class AiAdminController extends Controller
{
    public function __construct(private readonly AiPolicy $policy, private readonly AiGuard $guard, private readonly AiModelSettings $models, private readonly SmartFeedback $feedback, private readonly HybridRecommender $recommender) {}

    // ---- settings ------------------------------------------------------------------------------------

    public function settings(): JsonResponse
    {
        $policy = $this->policy->all();
        $conns = collect($this->models->forAdmin()['connections'])->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'driver' => $c['driver'], 'data_residency' => $c['data_residency'] ?? 'external', 'enabled' => $c['enabled'] ?? true]);
        $models = collect($this->models->all()['models'])->where('enabled', true)->map(fn ($m) => ['id' => $m['id'], 'label' => $m['label'] ?: $m['model'], 'connection_id' => $m['connection_id'], 'residency' => $conns->firstWhere('id', $m['connection_id'])['data_residency'] ?? 'external'])->values();
        $rows = [];
        foreach (AiPolicy::FEATURES as $f) {
            $rows[$f] = $policy['features'][$f] + ['usable' => $this->guard->available($f, true)];
        }

        return response()->json(['data' => ['policy' => ['residency_enforced' => $policy['residency_enforced'], 'redaction' => $policy['redaction'], 'retention_days' => $policy['retention_days'], 'features' => $rows, 'recommendations' => $policy['recommendations']], 'connections' => $conns, 'models' => $models, 'default_residency' => $this->models->resolve('text')['connection']['data_residency'] ?? null,
            'stats' => $this->logStats(), 'index' => ['chunks' => Embedding::count(), 'sources' => Embedding::distinct()->count('source_id')]]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'residency_enforced' => ['boolean'], 'redaction' => ['boolean'], 'retention_days' => ['integer', 'between:1,365'],
            'features' => ['array'], 'features.*.enabled' => ['boolean'], 'features.*.model_id' => ['nullable', 'string', 'max:24'], 'features.*.allow_external' => ['boolean'],
            'recommendations' => ['array'], 'recommendations.weights' => ['array'], 'recommendations.weights.*' => ['integer', 'between:0,100'], 'recommendations.ab_test' => ['boolean'], 'recommendations.ab_share' => ['integer', 'between:0,100'],
        ]);
        $this->policy->save($d, $this->user()->id);

        return $this->settings();
    }

    /** @return array<string, mixed> */
    private function logStats(): array
    {
        $since = now()->subDays(30);
        $by = AiLog::where('created_at', '>=', $since)->selectRaw('feature, status, count(*) as n')->groupBy('feature', 'status')->get();

        return ['calls_30d' => (int) $by->sum('n'), 'blocked_30d' => (int) $by->where('status', 'blocked')->sum('n'), 'redactions_30d' => (int) AiLog::where('created_at', '>=', $since)->sum('redactions'), 'by_feature' => $by->groupBy('feature')->map(fn ($g) => $g->pluck('n', 'status'))->all()];
    }

    public function logs(Request $request): JsonResponse
    {
        $rows = AiLog::when($request->query('feature'), fn ($q, $f) => $q->where('feature', $f))->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('created_at')->paginate($this->perPage($request, 30));

        return response()->json($rows);
    }

    public function recommendationStats(): JsonResponse
    {
        return response()->json(['data' => $this->recommender->stats(30)]);
    }

    public function reindex(Indexer $indexer): JsonResponse
    {
        return response()->json(['data' => ['chunks' => $indexer->all()]]);
    }

    // ---- grader's drafts -----------------------------------------------------------------------------

    public function drafts(AssessmentAttempt $attempt): JsonResponse
    {
        $this->feedback->draftEssays($attempt);

        return response()->json(['data' => AiFeedbackDraft::where('attempt_id', $attempt->id)->get()->map(fn ($d) => ['id' => $d->id, 'question_id' => $d->question_id, 'draft_text' => $d->draft_text, 'suggested_score' => $d->suggested_score, 'max_points' => $d->max_points, 'criteria' => $d->rubric, 'source' => $d->source, 'status' => $d->status, 'final_score' => $d->final_score])]);
    }

    public function review(Request $request, AiFeedbackDraft $aiDraft, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['accept', 'edit', 'reject'], true), 404);
        $d = $request->validate(['score' => [$action === 'edit' ? 'required' : 'nullable', 'numeric', 'min:0'], 'comment' => ['nullable', 'string', 'max:3000']]);
        $draft = $this->feedback->review($aiDraft, $this->user(), $action, isset($d['score']) ? (float) $d['score'] : null, $d['comment'] ?? null);

        return response()->json(['data' => ['id' => $draft->id, 'status' => $draft->status, 'final_score' => $draft->final_score]]);
    }

    // ---- adaptive rules ------------------------------------------------------------------------------

    public function rules(Program $program): JsonResponse
    {
        return response()->json(['data' => AdaptiveRule::with('skill:id,name_ar,name_en')->where('program_id', $program->id)->get()]);
    }

    public function saveRules(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate(['rules' => ['array', 'max:50'], 'rules.*.skill_id' => ['required', 'uuid', 'exists:skills,id'], 'rules.*.action' => ['required', Rule::in(['skip_module', 'add_lesson'])], 'rules.*.module_id' => ['nullable', 'uuid'], 'rules.*.lesson_id' => ['nullable', 'uuid'],
            'rules.*.skip_at' => ['nullable', 'numeric', 'between:0.5,1'], 'rules.*.remedial_below' => ['nullable', 'numeric', 'between:0,0.9'], 'rules.*.is_active' => ['boolean']]);
        foreach ($d['rules'] ?? [] as $r) {
            if ($r['action'] === 'skip_module' && (empty($r['module_id']) || ! CourseModule::where('program_id', $program->id)->whereKey($r['module_id'])->exists())) {
                throw new BusinessRuleException('Choose a module of this programme to skip.', 'module_required');
            }
            if ($r['action'] === 'add_lesson' && (empty($r['lesson_id']) || ! CourseLesson::where('program_id', $program->id)->whereKey($r['lesson_id'])->exists())) {
                throw new BusinessRuleException('Choose a lesson of this programme to add.', 'lesson_required');
            }
        }
        AdaptiveRule::where('program_id', $program->id)->delete();
        foreach ($d['rules'] ?? [] as $r) {
            AdaptiveRule::create(['program_id' => $program->id, 'skill_id' => $r['skill_id'], 'action' => $r['action'], 'module_id' => $r['action'] === 'skip_module' ? $r['module_id'] : null, 'lesson_id' => $r['action'] === 'add_lesson' ? $r['lesson_id'] : null,
                'skip_at' => $r['skip_at'] ?? 0.85, 'remedial_below' => $r['remedial_below'] ?? 0.5, 'is_active' => $r['is_active'] ?? true]);
        }

        return $this->rules($program);
    }

    /** Competencies to choose from (the trainer's role has no access to the framework screens). */
    public function skills(Request $request): JsonResponse
    {
        $like = '%'.mb_strtolower(trim((string) $request->query('q', ''))).'%';

        return response()->json(['data' => Skill::where('is_active', true)->when($request->query('q'), fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name_ar) like ?', [$like])->orWhereRaw('lower(name_en) like ?', [$like])))->orderBy('name_en')->limit(300)->get(['id', 'code', 'name_ar', 'name_en'])]);
    }

    public function remedial(Request $request, Program $program, RemedialBuilder $builder): JsonResponse
    {
        $d = $request->validate(['skill_id' => ['required', 'uuid', 'exists:skills,id']]);
        $lesson = $builder->draft($program, Skill::findOrFail($d['skill_id']), $this->user());

        return response()->json(['data' => ['lesson_id' => $lesson->id, 'status' => $lesson->status, 'ai_generated' => (bool) ($lesson->settings['ai_generated'] ?? false)]], 201);
    }

    // ---- forecasts and risks -------------------------------------------------------------------------

    public function forecasts(Request $request): JsonResponse
    {
        $d = $request->validate(['dimension' => ['nullable', Rule::in(['competency', 'job', 'school'])], 'year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = (int) ($d['year'] ?? Forecast::max('year') ?? now()->year + 1);
        $rows = Forecast::where('year', $year)->when($d['dimension'] ?? null, fn ($q, $x) => $q->where('dimension', $x))->orderByDesc('value')->limit(200)->get()
            ->map(fn (Forecast $f) => ['id' => $f->id, 'dimension' => $f->dimension, 'subject_id' => $f->subject_id, 'label' => $f->label, 'year' => $f->year, 'value' => $f->value, 'low' => $f->low, 'high' => $f->high, 'model' => $f->model, 'explanation' => $f->explanation, 'history' => $f->history, 'confidence' => ForecastService::confidence($f)]);

        return response()->json(['data' => $rows->values(), 'year' => $year, 'years' => Forecast::distinct()->orderBy('year')->pluck('year')]);
    }

    public function runForecasts(ForecastService $service, RiskService $risks): JsonResponse
    {
        return response()->json(['data' => ['forecasts' => $service->run(), 'risks' => $risks->run()]]);
    }

    public function risks(Request $request): JsonResponse
    {
        $rows = RiskFlag::whereNull('resolved_at')->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))->orderByDesc('score')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (RiskFlag $r) => ['id' => $r->id, 'type' => $r->type, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'label' => $r->label, 'score' => $r->score, 'reasons' => $r->reasons, 'created_at' => $r->created_at?->toIso8601String()]), 'counts' => RiskFlag::whereNull('resolved_at')->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')]);
    }

    public function resolveRisk(RiskFlag $riskFlag): JsonResponse
    {
        $riskFlag->update(['resolved_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    /** Turns forecasts into suggested items of a draft plan. Nothing is added to an approved plan. */
    public function toPlan(Request $request, AnnualPlanService $plans): JsonResponse
    {
        $d = $request->validate(['plan_id' => ['required', 'uuid', 'exists:training_plans,id'], 'forecast_ids' => ['required', 'array', 'min:1', 'max:30'], 'forecast_ids.*' => ['uuid']]);
        $plan = TrainingPlan::findOrFail($d['plan_id']);
        $added = [];
        foreach (Forecast::whereIn('id', $d['forecast_ids'])->get() as $f) {
            $conf = ForecastService::confidence($f);
            $seats = (int) ceil($f->value);
            $item = $plans->addItem($plan, [
                'title_ar' => 'برنامج مقترح: '.$f->label, 'title_en' => 'Suggested programme: '.$f->label, 'priority' => $conf >= 0.6 ? 'high' : 'medium', 'priority_score' => round($conf * 100, 2), 'planned_groups' => max(1, (int) ceil($seats / 25)), 'planned_seats' => $seats,
                'source' => 'forecast', 'source_refs' => ['forecast_id' => $f->id, 'dimension' => $f->dimension, 'confidence' => $conf, 'range' => [$f->low, $f->high]], 'rationale_en' => Str::limit((string) $f->explanation, 480), 'reason' => 'Added from the AI forecast',
            ], $this->user());
            $added[] = $item->id;
        }

        return response()->json(['data' => ['added' => $added]], 201);
    }
}
