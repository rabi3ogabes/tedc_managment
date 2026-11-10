<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyDistribution;
use App\Services\Surveys\SurveyCollector;
use App\Services\Surveys\SurveyDistributor;
use App\Services\Surveys\SurveyFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public side of a survey: a public link (device or verified e-mail as the duplicate rule) or a personal invitation.
 * No session, no account; the respondent is known only by a pseudonymous key.
 */
class PublicSurveyController extends Controller
{
    public function __construct(private readonly SurveyDistributor $distributor, private readonly SurveyCollector $collector, private readonly SurveyFiles $files) {}

    // Public link ----------------------------------------------------------------------------------------------------

    public function show(string $slug): JsonResponse
    {
        try {
            $d = $this->distributor->resolvePublic($slug);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 410);
        }

        return response()->json(['data' => $this->definition($d->survey) + ['rule' => $d->duplicate_rule, 'requires_code' => $d->duplicate_rule === 'email_code']]);
    }

    public function sendCode(Request $request, string $slug): JsonResponse
    {
        $d = $this->distributor->resolvePublic($slug);
        abort_unless($d->duplicate_rule === 'email_code', 422);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        return response()->json(['data' => $this->distributor->sendCode($d, $data['email'])]);
    }

    public function verifyCode(Request $request, string $slug): JsonResponse
    {
        $d = $this->distributor->resolvePublic($slug);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190'], 'code' => ['required', 'string', 'size:6']]);

        return response()->json(['data' => $this->distributor->verifyCode($d, $data['email'], $data['code'])]);
    }

    public function draft(Request $request, string $slug): JsonResponse
    {
        $d = $this->distributor->resolvePublic($slug);
        [$key] = $this->keyFor($request, $d);
        $data = $request->validate(['answers' => ['present', 'array'], 'page' => ['nullable', 'integer', 'min:0', 'max:500']]);
        $s = $this->collector->saveDraftByKey($d->survey, $key, $data['answers'], (int) ($data['page'] ?? 0));

        return response()->json(['data' => ['saved_at' => $s->last_seen_at]]);
    }

    /** The respondent's draft and, under a dedupe rule, their earlier answers. */
    public function resume(Request $request, string $slug): JsonResponse
    {
        $d = $this->distributor->resolvePublic($slug);
        [$key, $dedupe] = $this->keyFor($request, $d);
        $draft = $this->collector->draftByKey($d->survey, $key);
        $existing = $dedupe ? $this->collector->submissionByKey($d->survey, $key) : null;

        return response()->json(['data' => [
            'draft' => $draft ? ['answers' => $draft->draft_answers, 'page' => $draft->page] : null,
            'submission' => $existing ? ['answers' => $existing->answers, 'submitted_at' => $existing->submitted_at] : null,
            'can_edit' => (bool) $existing && $d->survey->editPolicy() === 'until_close',
        ]]);
    }

    public function upload(Request $request, string $slug): JsonResponse
    {
        $d = $this->distributor->resolvePublic($slug);
        [$key] = $this->keyFor($request, $d);

        return $this->storeFile($request, $d->survey, $key);
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        $d = $this->distributor->resolvePublic($slug);
        [$key] = $this->keyFor($request, $d);
        $data = $request->validate(['answers' => ['present', 'array'], 'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);
        $r = $this->collector->submitExternal($d->survey, $key, $data['answers'], $data['duration_seconds'] ?? null, $d);

        return response()->json(['message' => $this->thanks($d->survey), 'edited' => $r['edited'], 'can_edit' => $d->duplicate_rule !== 'open' && $d->survey->editPolicy() === 'until_close']);
    }

    // Invitation -----------------------------------------------------------------------------------------------------

    public function showInvitation(string $token): JsonResponse
    {
        try {
            $i = $this->distributor->resolveInvitation($token);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 410);
        }
        $i->update(['opened_at' => $i->opened_at ?? now()]);
        $key = $this->distributor->respondentKey($i->survey, 'invitation:'.$i->id);
        $draft = $this->collector->draftByKey($i->survey, $key);
        $existing = $this->collector->submissionByKey($i->survey, $key);

        return response()->json(['data' => $this->definition($i->survey) + [
            'rule' => 'invitation', 'requires_code' => false, 'name' => $i->name, 'answered' => (bool) $i->responded_at,
            'draft' => $draft ? ['answers' => $draft->draft_answers, 'page' => $draft->page] : null,
            'submission' => $existing ? ['answers' => $existing->answers, 'submitted_at' => $existing->submitted_at] : null,
            'can_edit' => (bool) $existing && $i->survey->editPolicy() === 'until_close',
        ]]);
    }

    public function draftInvitation(Request $request, string $token): JsonResponse
    {
        $i = $this->distributor->resolveInvitation($token);
        $data = $request->validate(['answers' => ['present', 'array'], 'page' => ['nullable', 'integer', 'min:0', 'max:500']]);
        $s = $this->collector->saveDraftByKey($i->survey, $this->distributor->respondentKey($i->survey, 'invitation:'.$i->id), $data['answers'], (int) ($data['page'] ?? 0));

        return response()->json(['data' => ['saved_at' => $s->last_seen_at]]);
    }

    public function uploadInvitation(Request $request, string $token): JsonResponse
    {
        $i = $this->distributor->resolveInvitation($token);

        return $this->storeFile($request, $i->survey, $this->distributor->respondentKey($i->survey, 'invitation:'.$i->id));
    }

    public function submitInvitation(Request $request, string $token): JsonResponse
    {
        $i = $this->distributor->resolveInvitation($token);
        $data = $request->validate(['answers' => ['present', 'array'], 'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);
        $r = $this->collector->submitExternal($i->survey, $this->distributor->respondentKey($i->survey, 'invitation:'.$i->id), $data['answers'], $data['duration_seconds'] ?? null, null, $i);

        return response()->json(['message' => $this->thanks($i->survey), 'edited' => $r['edited'], 'can_edit' => $i->survey->editPolicy() === 'until_close']);
    }

    // Helpers -------------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: bool} the respondent key and whether the rule keeps one answer per key */
    private function keyFor(Request $request, SurveyDistribution $d): array
    {
        if ($d->duplicate_rule === 'email_code') {
            $email = $this->distributor->emailForToken($d, $request->input('respondent_token'));
            if (! $email) {
                throw new BusinessRuleException(__('messages.surveys.code_required'), 'survey_code_required');
            }

            return [$this->distributor->respondentKey($d->survey, 'email:'.$email), true];
        }
        $client = (string) $request->input('client_id', '');
        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $client)) {
            throw new BusinessRuleException(__('messages.surveys.client_required'), 'survey_client_required');
        }

        return [$this->distributor->respondentKey($d->survey, 'device:'.$client), $d->duplicate_rule === 'device'];
    }

    private function storeFile(Request $request, Survey $survey, string $key): JsonResponse
    {
        $data = $request->validate(['question_id' => ['required', 'string', 'max:40'], 'file' => ['required', 'file', 'max:25600']]);
        $question = collect($survey->currentVersion?->questions ?? [])->firstWhere('id', $data['question_id']);
        abort_unless($question, 422, __('messages.surveys.file_question'));
        $file = $this->files->upload($survey, $key, $question, $request->file('file'));

        return response()->json(['data' => $this->files->present($file)], 201);
    }

    private function definition(Survey $survey): array
    {
        $v = $survey->currentVersion;

        return [
            'id' => $survey->id, 'title' => $survey->title(), 'title_ar' => $survey->title_ar, 'title_en' => $survey->title_en, 'description_ar' => $survey->description_ar, 'description_en' => $survey->description_en,
            'privacy_mode' => 'anonymous', 'is_open' => $survey->isOpen(), 'closes_at' => $survey->closes_at, 'retention_days' => $survey->retention_days, 'version' => $v?->number, 'questions' => $v?->questions ?? [], 'settings' => $v?->settings ?? [],
        ];
    }

    private function thanks(Survey $survey): string
    {
        $settings = $survey->currentVersion?->settings ?? [];
        $text = app()->getLocale() === 'en' ? ($settings['thank_you_en'] ?? $settings['thank_you'] ?? null) : ($settings['thank_you'] ?? null);

        return $text ?: __('messages.surveys.thank_you');
    }
}
