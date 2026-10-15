<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\Surveys\LogicEngine;
use App\Services\Surveys\SurveyDefinition;
use App\Services\Surveys\SurveyPrivacy;
use App\Services\Surveys\SurveyWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Survey Studio v2: the administrator's side of the survey engine (definitions, lifecycle, versions). */
class SurveyController extends Controller
{
    public function __construct(private readonly SurveyWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $q = Survey::query()->withCount(['participants', 'submissions'])
            ->when($request->query('status'), fn ($b, $v) => $b->whereIn('status', explode(',', $v)))
            ->when($request->query('kind'), fn ($b, $v) => $b->where('kind', $v))
            ->when($request->query('privacy_mode'), fn ($b, $v) => $b->where('privacy_mode', $v))
            ->when($request->query('q'), fn ($b, $v) => $b->where(fn ($w) => $w->where('title_ar', 'like', "%$v%")->orWhere('title_en', 'like', "%$v%")))
            ->when($request->boolean('mine'), fn ($b) => $b->where('owner_id', $this->user()->id))
            ->orderByDesc('updated_at');
        $page = $q->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (Survey $s) => $this->summary($s))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Everything the studio needs to build a survey: question types, operators, modes, segment fields, defaults. */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => SurveyDefinition::catalog(),
            'operators' => SurveyDefinition::OPERATORS,
            'privacy_modes' => Survey::PRIVACY_MODES,
            'edit_policies' => Survey::EDIT_POLICIES,
            'kinds' => Survey::KINDS,
            'segment_fields' => SurveyPrivacy::SEGMENT_FIELDS,
            'accept' => SurveyDefinition::ACCEPT,
            'defaults' => ['segment_threshold' => (int) config('tedc.surveys.segment_threshold', 5), 'retention_days' => (int) config('tedc.surveys.retention_days', 1095)],
            'max_questions' => SurveyDefinition::MAX_QUESTIONS,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $studioSurvey = $this->workflow->create($this->user(), $data);

        return response()->json(['data' => $this->detail($studioSurvey)], 201);
    }

    public function show(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->detail($studioSurvey)]);
    }

    public function update(Request $request, Survey $studioSurvey): JsonResponse
    {
        $studioSurvey = $this->workflow->update($studioSurvey, $this->validated($request, false), $this->user());

        return response()->json(['data' => $this->detail($studioSurvey)]);
    }

    public function destroy(Survey $studioSurvey): JsonResponse
    {
        // Nothing is deleted: archiving keeps the versions and the answers for the retention period.
        $this->workflow->archive($studioSurvey, $this->user());

        return response()->json(['data' => $this->detail($studioSurvey)]);
    }

    public function duplicate(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->duplicate($studioSurvey, $this->user()))], 201);
    }

    public function submitReview(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->submitForReview($studioSurvey, $this->user()))]);
    }

    public function approve(Request $request, Survey $studioSurvey): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->detail($this->workflow->approve($studioSurvey, $this->user(), $data['note'] ?? null))]);
    }

    public function returnToDraft(Request $request, Survey $studioSurvey): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->detail($this->workflow->returnToDraft($studioSurvey, $this->user(), $data['note']))]);
    }

    public function publish(Survey $studioSurvey): JsonResponse
    {
        $result = $this->workflow->publish($studioSurvey, $this->user());

        return response()->json(['data' => $this->detail($studioSurvey->refresh())] + $result);
    }

    public function close(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->close($studioSurvey, $this->user()))]);
    }

    public function reopen(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->reopen($studioSurvey, $this->user()))]);
    }

    public function remind(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['reminded' => $this->workflow->remind($studioSurvey, $this->user())]);
    }

    public function versions(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $studioSurvey->versions()->withCount('submissions')->get()->map(fn ($v) => [
            'id' => $v->id, 'number' => $v->number, 'checksum' => $v->checksum, 'published_at' => $v->published_at, 'published_by' => $v->publisher?->displayName(),
            'questions' => count($v->questions), 'submissions' => $v->submissions_count, 'current' => $v->id === $studioSurvey->current_version_id,
        ])]);
    }

    public function version(Survey $studioSurvey, int $number): JsonResponse
    {
        $v = $studioSurvey->versions()->where('number', $number)->firstOrFail();

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'checksum' => $v->checksum, 'published_at' => $v->published_at, 'questions' => $v->questions, 'settings' => $v->settings]]);
    }

    public function events(Survey $studioSurvey): JsonResponse
    {
        return response()->json(['data' => $studioSurvey->events()->with('actor:id,name,name_ar')->limit(200)->get()->map(fn ($e) => [
            'event' => $e->event, 'actor' => $e->actor?->displayName(), 'data' => $e->data, 'at' => $e->created_at,
        ])]);
    }

    /** Runs the logic over a set of answers so the studio can show which questions a respondent would see. */
    public function previewLogic(Request $request, Survey $studioSurvey): JsonResponse
    {
        $data = $request->validate(['answers' => ['present', 'array'], 'questions' => ['nullable', 'array']]);
        $questions = isset($data['questions']) ? SurveyDefinition::normalize($data['questions']) : $studioSurvey->questions;
        $result = LogicEngine::evaluate($questions, $data['answers']);

        return response()->json(['data' => ['visible' => $result['visible'], 'pages' => $result['pages'], 'ended' => $result['ended']]]);
    }

    public function audienceOptions(SurveyAudience $audience): JsonResponse
    {
        return response()->json(['data' => $audience->options()]);
    }

    public function audiencePreview(Request $request, SurveyAudience $audience): JsonResponse
    {
        $data = $request->validate(['audience' => ['present', 'array']]);

        return response()->json(['data' => $audience->preview($data['audience'], app()->getLocale())]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'kind' => ['sometimes', Rule::in(Survey::KINDS)],
            'title_ar' => [$r, 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'],
            'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'],
            'privacy_mode' => ['sometimes', Rule::in(Survey::PRIVACY_MODES)],
            'program_id' => ['nullable', 'uuid', 'exists:programs,id'],
            'questions' => [$r, 'array', 'max:'.SurveyDefinition::MAX_QUESTIONS], 'questions.*' => ['array'],
            'audience' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'retention_days' => ['nullable', 'integer', 'min:30', 'max:3650'],
            'legal_hold' => ['sometimes', 'boolean'],
        ]);
    }

    private function summary(Survey $s): array
    {
        return [
            'id' => $s->id, 'kind' => $s->kind, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'status' => $s->status, 'privacy_mode' => $s->privacy_mode,
            'legacy_type' => $s->legacy_type, 'legacy_id' => $s->legacy_id, 'program_id' => $s->program_id,
            'legal_hold' => (bool) $s->legal_hold, 'answers_purged_at' => $s->answers_purged_at, 'key_deleted_at' => $s->key_deleted_at, 'retire_at' => app(\App\Services\Surveys\SurveyRetention::class)->retireAt($s),
            'owner' => $s->owner?->displayName(), 'opens_at' => $s->opens_at, 'closes_at' => $s->closes_at, 'published_at' => $s->published_at, 'closed_at' => $s->closed_at,
            'participants' => $s->participants_count ?? $s->participants()->count(), 'submissions' => $s->submissions_count ?? $s->submissions()->count(),
            'is_open' => $s->isOpen(), 'updated_at' => $s->updated_at,
        ];
    }

    private function detail(Survey $s): array
    {
        $s->loadMissing('currentVersion', 'owner', 'reviewer');
        $participants = $s->participants()->count();
        $answered = $s->participants()->whereNotNull('responded_at')->count();

        return $this->summary($s) + [
            'description_ar' => $s->description_ar, 'description_en' => $s->description_en, 'program_id' => $s->program_id,
            'questions' => $s->questions, 'audience' => $s->audience, 'settings' => $s->settings, 'timezone' => $s->timezone, 'retention_days' => $s->retention_days,
            'approval' => ['approved' => $this->workflow->isApproved($s), 'required' => (bool) $s->setting('requires_approval', true), 'reviewed_by' => $s->reviewer?->displayName(), 'reviewed_at' => $s->reviewed_at, 'note' => $s->review_note],
            'version' => $s->currentVersion ? ['number' => $s->currentVersion->number, 'published_at' => $s->currentVersion->published_at] : null,
            'has_unpublished_changes' => $this->workflow->hasUnpublishedChanges($s),
            'stats' => ['participants' => $participants, 'answered' => $answered, 'response_rate' => $participants ? round($answered / $participants * 100, 1) : null, 'submissions' => $s->submissions()->count()],
        ];
    }
}
