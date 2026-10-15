<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Services\Surveys\LogicEngine;
use App\Services\Surveys\SurveyCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Survey Studio v2: the signed-in participant's side (list, definition, autosaved draft, submit, edit). */
class MySurveyController extends Controller
{
    public function __construct(private readonly SurveyCollector $collector) {}

    public function index(): JsonResponse
    {
        $rows = Survey::query()->whereIn('status', ['published', 'closed'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $this->user()->id))
            ->with('currentVersion')->with('participants', fn ($q) => $q->where('user_id', $this->user()->id))
            ->orderByRaw("case when status = 'published' then 0 else 1 end")->orderByDesc('published_at')->get();

        return response()->json(['data' => $rows->map(function (Survey $s) {
            $p = $s->participants->first();

            return [
                'id' => $s->id, 'kind' => $s->kind, 'title' => $s->title(), 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'description' => app()->getLocale() === 'en' && $s->description_en ? $s->description_en : $s->description_ar,
                'privacy_mode' => $s->privacy_mode, 'status' => $s->status, 'is_open' => $s->isOpen(), 'closes_at' => $s->closes_at,
                'answered' => (bool) $p?->responded_at, 'responded_at' => $p?->responded_at, 'can_edit' => $s->isOpen() && $p?->responded_at && ! $s->isAnonymous() && $s->editPolicy() === 'until_close',
                'questions' => count(array_filter($s->currentVersion?->questions ?? [], fn ($q) => ! in_array($q['type'], ['section', 'instructions', 'page_break'], true))),
            ];
        })]);
    }

    public function show(Survey $studioSurvey): JsonResponse
    {
        $participant = $this->collector->participant($studioSurvey, $this->user());
        $version = $studioSurvey->currentVersion ?: abort(404);
        $submission = $this->collector->submissionOf($studioSurvey, $this->user());
        $draft = $this->collector->draftOf($studioSurvey, $this->user());

        return response()->json(['data' => [
            'id' => $studioSurvey->id, 'kind' => $studioSurvey->kind, 'title' => $studioSurvey->title(), 'title_ar' => $studioSurvey->title_ar, 'title_en' => $studioSurvey->title_en,
            'description_ar' => $studioSurvey->description_ar, 'description_en' => $studioSurvey->description_en,
            'privacy_mode' => $studioSurvey->privacy_mode, 'is_open' => $studioSurvey->isOpen(), 'closes_at' => $studioSurvey->closes_at,
            'version' => $version->number, 'questions' => $version->questions, 'settings' => $version->settings,
            'answered' => (bool) $participant->responded_at,
            'can_edit' => $studioSurvey->isOpen() && (bool) $participant->responded_at && ! $studioSurvey->isAnonymous() && $studioSurvey->editPolicy() === 'until_close',
            'submission' => $submission ? ['answers' => $submission->answers, 'submitted_at' => $submission->submitted_at, 'edited_at' => $submission->edited_at, 'edit_count' => $submission->edit_count] : null,
            'draft' => $draft ? ['answers' => $draft->draft_answers, 'page' => $draft->page, 'saved_at' => $draft->last_seen_at] : null,
        ]]);
    }

    /** Autosave: the answers so far and the page the person is on. */
    public function draft(Request $request, Survey $studioSurvey): JsonResponse
    {
        $data = $request->validate(['answers' => ['present', 'array'], 'page' => ['nullable', 'integer', 'min:0', 'max:500']]);
        $session = $this->collector->saveDraft($studioSurvey, $this->user(), $data['answers'], (int) ($data['page'] ?? 0));

        return response()->json(['data' => ['saved_at' => $session->last_seen_at, 'expires_at' => $session->expires_at]]);
    }

    public function submit(Request $request, Survey $studioSurvey): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['present', 'array'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'source' => ['nullable', 'in:web,mobile'],
        ]);
        $result = $this->collector->submit($studioSurvey, $this->user(), $data['answers'], $data['duration_seconds'] ?? null, $data['source'] ?? 'web');
        $settings = $studioSurvey->currentVersion?->settings ?? [];
        $thanks = app()->getLocale() === 'en' ? ($settings['thank_you_en'] ?? $settings['thank_you'] ?? null) : ($settings['thank_you'] ?? null);

        return response()->json([
            'message' => $thanks ?: __('messages.surveys.thank_you'),
            'edited' => $result['edited'],
            'can_edit' => $studioSurvey->isOpen() && ! $studioSurvey->isAnonymous() && $studioSurvey->editPolicy() === 'until_close',
        ]);
    }

    /** Which questions and pages the person sees for the answers so far (the renderers can also compute this locally). */
    public function logic(Request $request, Survey $studioSurvey): JsonResponse
    {
        $this->collector->participant($studioSurvey, $this->user());
        $data = $request->validate(['answers' => ['present', 'array']]);
        $result = LogicEngine::evaluate($studioSurvey->currentVersion?->questions ?? [], $data['answers']);

        return response()->json(['data' => ['visible' => $result['visible'], 'pages' => $result['pages'], 'ended' => $result['ended']]]);
    }
}
