<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\Survey;
use App\Models\SurveyDistribution;
use App\Models\SurveyInvitation;
use App\Models\SurveyParticipant;
use App\Models\SurveySession;
use App\Models\SurveySubmission;
use App\Models\SurveySubmissionEdit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Collects answers: from signed-in participants under the survey's privacy mode, and (Gate 4) from public respondents
 * identified only by a pseudonymous key (device, verified e-mail or invitation token). Autosaved drafts, one answer per
 * person, edits with history while the survey is open when the policy allows, and file answers bound on submit.
 */
class SurveyCollector
{
    public function __construct(private readonly SurveyFiles $files) {}

    // Signed-in participants ---------------------------------------------------------------------------------------

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
            return $survey->submissions()->whereNull('context_id')->where('respondent_user_id', $user->id)->first();
        }
        if ($survey->isConfidential() && $survey->pseudonym_key) {
            return $survey->submissions()->whereNull('context_id')->where('respondent_pseudonym', SurveyPrivacy::pseudonym($survey, $user))->first();
        }

        return null;
    }

    public function draftOf(Survey $survey, User $user): ?SurveySession
    {
        if (! $survey->pseudonym_key && ! $survey->isIdentified()) {
            return null;
        }

        return $this->draftByKey($survey, SurveyPrivacy::respondentKey($survey, $user));
    }

    public function saveDraft(Survey $survey, User $user, array $answers, int $page = 0): SurveySession
    {
        $this->assertOpen($survey);
        $this->participant($survey, $user);

        return $this->saveDraftByKey($survey, SurveyPrivacy::respondentKey($survey, $user), $answers, $page);
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
        $existing = $this->submissionOf($survey, $user);
        if ($survey->isAnonymous() && $participant->responded_at) {
            throw new BusinessRuleException(__('messages.surveys.already_answered'), 'survey_already_answered');
        }
        $key = SurveyPrivacy::respondentKey($survey, $user);
        $result = $this->persist($survey, $key, SurveyPrivacy::columns($survey, $user), $existing, $answers, $durationSeconds, $source,
            $survey->isIdentified() ? ['segment_snapshot' => SurveyPrivacy::segmentSnapshot($survey, $user->employee)] : []);
        $participant->update(['responded_at' => $participant->responded_at ?? now()]);

        return $result;
    }

    // Public respondents (device, verified e-mail, invitation) -------------------------------------------------------

    public function draftByKey(Survey $survey, string $key): ?SurveySession
    {
        $draft = $survey->sessions()->where('respondent_key', $key)->first();
        // A draft written against an older version is useless: the questions may have changed.
        if ($draft && ($draft->version_id !== $survey->current_version_id || ($draft->expires_at && $draft->expires_at->isPast()))) {
            $draft->delete();

            return null;
        }

        return $draft;
    }

    public function saveDraftByKey(Survey $survey, string $key, array $answers, int $page = 0): SurveySession
    {
        $this->assertOpen($survey);
        if (strlen(json_encode($answers)) > 200_000) {
            throw new BusinessRuleException(__('messages.surveys.draft_too_large'), 'survey_draft_too_large');
        }

        return SurveySession::updateOrCreate(
            ['survey_id' => $survey->id, 'respondent_key' => $key],
            ['version_id' => $survey->current_version_id, 'draft_answers' => $answers, 'page' => max(0, $page), 'last_seen_at' => now(),
                'expires_at' => now()->addDays((int) config('tedc.surveys.draft_ttl_days', 30))],
        );
    }

    /** The public respondent's earlier submission, when the rule keeps one per key. */
    public function submissionByKey(Survey $survey, string $key): ?SurveySubmission
    {
        return $survey->submissions()->whereNull('context_id')->where('respondent_pseudonym', $key)->first();
    }

    // Context answers: an evaluation assignment (Gate 5) ------------------------------------------------------------

    /** The person's answer inside one context, whatever the mode (the context itself is addressed to the person). */
    public function submissionFor(Survey $survey, User $user, string $contextId): ?SurveySubmission
    {
        $q = $survey->submissions()->where('context_id', $contextId);

        return $survey->isIdentified() ? $q->where('respondent_user_id', $user->id)->first() : ($survey->pseudonym_key ? $q->where('respondent_pseudonym', SurveyPrivacy::contextKey($survey, $user, $contextId))->first() : null);
    }

    public function draftFor(Survey $survey, User $user, string $contextId): ?SurveySession
    {
        return $survey->pseudonym_key || $survey->isIdentified() ? $this->draftByKey($survey, SurveyPrivacy::contextKey($survey, $user, $contextId)) : null;
    }

    public function saveDraftFor(Survey $survey, User $user, string $contextId, array $answers, int $page = 0): SurveySession
    {
        return $this->saveDraftByKey($survey, SurveyPrivacy::contextKey($survey, $user, $contextId), $answers, $page);
    }

    /**
     * Submits the person's answer for one context. No participation row is needed: the context (the assignment) is the invitation.
     *
     * @return array{submission: SurveySubmission, edited: bool}
     */
    public function submitFor(Survey $survey, User $user, string $contextId, array $answers, ?int $durationSeconds = null, string $source = 'web'): array
    {
        $this->assertOpen($survey);
        $key = SurveyPrivacy::contextKey($survey, $user, $contextId);
        $existing = $this->submissionFor($survey, $user, $contextId);

        return $this->persist($survey, $key, [
            'respondent_user_id' => $survey->isIdentified() ? $user->id : null, 'respondent_pseudonym' => $survey->isIdentified() ? null : $key, 'context_id' => $contextId,
            'segment_snapshot' => SurveyPrivacy::segmentSnapshot($survey, $user->employee),
        ], $existing, $answers, $durationSeconds, $source);
    }

    /**
     * A submission through a public link or an invitation. Nothing about the person is stored: the pseudonymous key
     * enforces one answer per device / verified address / invitation when the rule asks for it.
     *
     * @return array{submission: SurveySubmission, edited: bool}
     */
    public function submitExternal(Survey $survey, string $key, array $answers, ?int $durationSeconds, ?SurveyDistribution $distribution = null, ?SurveyInvitation $invitation = null): array
    {
        $this->assertOpen($survey);
        $dedupe = $invitation !== null || ($distribution && $distribution->duplicate_rule !== 'open');
        $existing = $dedupe ? $this->submissionByKey($survey, $key) : null;
        if ($invitation && $invitation->responded_at && ! $existing) {
            throw new BusinessRuleException(__('messages.surveys.invitation_used'), 'survey_invitation_used');
        }
        if ($distribution && ! $existing && $distribution->isFull()) {
            throw new BusinessRuleException(__('messages.surveys.link_full'), 'survey_link_full');
        }
        $result = $this->persist($survey, $key, ['respondent_user_id' => null, 'respondent_pseudonym' => $dedupe ? $key : null, 'segment_snapshot' => null], $existing, $answers, $durationSeconds, $invitation ? 'invitation' : 'public');
        if (! $result['edited']) {
            $distribution?->increment('submissions_count');
            $invitation?->update(['responded_at' => now()]);
        }

        return $result;
    }

    // Shared --------------------------------------------------------------------------------------------------------

    /** @return array{submission: SurveySubmission, edited: bool} */
    private function persist(Survey $survey, string $key, array $columns, ?SurveySubmission $existing, array $answers, ?int $durationSeconds, string $source, array $editPatch = []): array
    {
        $version = $survey->currentVersion ?: abort(409, __('messages.surveys.no_version'));
        if ($existing && $survey->editPolicy() === 'never') {
            throw new BusinessRuleException(__('messages.surveys.edits_closed'), 'survey_edits_closed');
        }
        $clean = AnswerValidator::validate($version->questions, $answers);
        $this->files->assertAnswerFiles($survey, $key, $version->questions, $clean);
        $source = in_array($source, SurveySubmission::SOURCES, true) ? $source : 'web';

        return DB::transaction(function () use ($survey, $key, $columns, $existing, $clean, $durationSeconds, $source, $editPatch, $version) {
            if ($existing) {
                SurveySubmissionEdit::create(['submission_id' => $existing->id, 'version_id' => $existing->version_id, 'previous_answers' => $existing->answers, 'created_at' => now()]);
                $existing->update(['version_id' => $version->id, 'answers' => $clean, 'edited_at' => now(), 'edit_count' => $existing->edit_count + 1, 'source' => $source] + $editPatch);
                $submission = $existing;
            } else {
                $submission = SurveySubmission::create($columns + [
                    'survey_id' => $survey->id, 'version_id' => $version->id, 'answers' => $clean, 'status' => 'submitted', 'source' => $source,
                    'duration_seconds' => $durationSeconds !== null ? max(0, min(86400, $durationSeconds)) : null, 'submitted_at' => now(),
                ]);
            }
            $this->files->attach($survey, $key, $submission, $clean);
            $survey->sessions()->where('respondent_key', $key)->delete();
            // The event never names the person: in confidential, anonymous and public surveys that is the whole point.
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
