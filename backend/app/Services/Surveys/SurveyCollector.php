<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\Survey;
use App\Models\SurveyParticipant;
use App\Models\SurveySession;
use App\Models\SurveySubmission;
use App\Models\SurveySubmissionEdit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Collects answers from signed-in participants: autosaved drafts, submission under the survey's privacy mode,
 * one answer per person, and edits with history while the survey is open (when the policy allows).
 * Public and invitation links arrive with Gate 4 and reuse these rules with a token instead of a user.
 */
class SurveyCollector
{
    /** The participant row, or 404 when the person was not invited. */
    public function participant(Survey $survey, User $user): SurveyParticipant
    {
        abort_if($survey->status === 'draft' || $survey->status === 'archived', 404);

        return $survey->participants()->where('user_id', $user->id)->firstOr(fn () => abort(403, __('messages.surveys.not_invited')));
    }

    /** The person's own submission, when the mode can find it (never in anonymous surveys). */
    public function submissionOf(Survey $survey, User $user): ?SurveySubmission
    {
        if ($survey->isIdentified()) {
            return $survey->submissions()->where('respondent_user_id', $user->id)->first();
        }
        if ($survey->isConfidential() && $survey->pseudonym_key) {
            return $survey->submissions()->where('respondent_pseudonym', SurveyPrivacy::pseudonym($survey, $user))->first();
        }

        return null;
    }

    public function draftOf(Survey $survey, User $user): ?SurveySession
    {
        if (! $survey->pseudonym_key && ! $survey->isIdentified()) {
            return null;
        }
        $draft = $survey->sessions()->where('respondent_key', SurveyPrivacy::respondentKey($survey, $user))->first();
        // A draft written against an older version is useless: the questions may have changed.
        if ($draft && ($draft->version_id !== $survey->current_version_id || ($draft->expires_at && $draft->expires_at->isPast()))) {
            $draft->delete();

            return null;
        }

        return $draft;
    }

    public function saveDraft(Survey $survey, User $user, array $answers, int $page = 0): SurveySession
    {
        $this->assertOpen($survey);
        $this->participant($survey, $user);
        if (strlen(json_encode($answers)) > 200_000) {
            throw new BusinessRuleException(__('messages.surveys.draft_too_large'), 'survey_draft_too_large');
        }

        return SurveySession::updateOrCreate(
            ['survey_id' => $survey->id, 'respondent_key' => SurveyPrivacy::respondentKey($survey, $user)],
            ['version_id' => $survey->current_version_id, 'draft_answers' => $answers, 'page' => max(0, $page), 'last_seen_at' => now(),
                'expires_at' => now()->addDays((int) config('tedc.surveys.draft_ttl_days', 30))],
        );
    }

    /**
     * Submits (or, while open and allowed, edits) the person's answers.
     *
     * @return array{submission: SurveySubmission, edited: bool}
     */
    public function submit(Survey $survey, User $user, array $answers, ?int $durationSeconds = null, string $source = 'web'): array
    {
        $this->assertOpen($survey);
        $participant = $this->participant($survey, $user);
        $version = $survey->currentVersion ?: abort(409, __('messages.surveys.no_version'));
        $existing = $this->submissionOf($survey, $user);

        if ($survey->isAnonymous() && $participant->responded_at) {
            throw new BusinessRuleException(__('messages.surveys.already_answered'), 'survey_already_answered');
        }
        if ($existing && $survey->editPolicy() === 'never') {
            throw new BusinessRuleException(__('messages.surveys.edits_closed'), 'survey_edits_closed');
        }

        $clean = AnswerValidator::validate($version->questions, $answers);
        $source = in_array($source, SurveySubmission::SOURCES, true) ? $source : 'web';

        return DB::transaction(function () use ($survey, $user, $participant, $version, $existing, $clean, $durationSeconds, $source) {
            if ($existing) {
                SurveySubmissionEdit::create(['submission_id' => $existing->id, 'version_id' => $existing->version_id, 'previous_answers' => $existing->answers, 'created_at' => now()]);
                $existing->update(['version_id' => $version->id, 'answers' => $clean, 'edited_at' => now(), 'edit_count' => $existing->edit_count + 1, 'source' => $source]
                    + ($survey->isIdentified() ? ['segment_snapshot' => SurveyPrivacy::segmentSnapshot($survey, $user->employee)] : []));
                $submission = $existing;
            } else {
                $submission = SurveySubmission::create(SurveyPrivacy::columns($survey, $user) + [
                    'survey_id' => $survey->id, 'version_id' => $version->id, 'answers' => $clean, 'status' => 'submitted', 'source' => $source,
                    'duration_seconds' => $durationSeconds !== null ? max(0, min(86400, $durationSeconds)) : null, 'submitted_at' => now(),
                ]);
            }
            $participant->update(['responded_at' => $participant->responded_at ?? now()]);
            $survey->sessions()->where('respondent_key', SurveyPrivacy::respondentKey($survey, $user))->delete();
            // The event never names the person: in confidential and anonymous surveys that is the whole point.
            SurveyEvents::respondent($survey, $existing ? 'submission_edited' : 'submission_received', ['version' => $version->number, 'source' => $source]);

            return ['submission' => $submission, 'edited' => (bool) $existing];
        });
    }

    private function assertOpen(Survey $survey): void
    {
        if (! $survey->isOpen()) {
            throw new BusinessRuleException(__('messages.surveys.not_open'), 'survey_not_open');
        }
    }
}
