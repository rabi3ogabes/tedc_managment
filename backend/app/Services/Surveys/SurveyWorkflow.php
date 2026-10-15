<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\Survey;
use App\Models\SurveyParticipant;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The survey lifecycle: draft → in_review → approved → published → closed (→ published again) → archived.
 * Publishing freezes a version; a published survey keeps accepting edits in its draft, which only reach
 * respondents through a new version, and only after the changed draft was approved again (or the
 * publisher may approve). Submissions always stay bound to the version they answered.
 */
class SurveyWorkflow
{
    public function __construct(private readonly SurveyAudience $audience, private readonly NotificationService $notifications) {}

    public function create(User $owner, array $data): Survey
    {
        $this->assertWindow($data['opens_at'] ?? null, $data['closes_at'] ?? null);
        $survey = Survey::create([
            'kind' => in_array($data['kind'] ?? null, Survey::KINDS, true) ? $data['kind'] : 'general',
            'title_ar' => $data['title_ar'],
            'title_en' => SurveyDefinition::text($data['title_en'] ?? null),
            'description_ar' => SurveyDefinition::text($data['description_ar'] ?? null, 2000),
            'description_en' => SurveyDefinition::text($data['description_en'] ?? null, 2000),
            'privacy_mode' => in_array($data['privacy_mode'] ?? null, Survey::PRIVACY_MODES, true) ? $data['privacy_mode'] : 'identified',
            'owner_id' => $owner->id,
            'program_id' => $data['program_id'] ?? null,
            'questions' => SurveyDefinition::normalize($data['questions'] ?? []),
            'audience' => isset($data['audience']) ? SurveyAudience::clean($data['audience']) : null,
            'settings' => SurveyDefinition::normalizeSettings($data['settings'] ?? []),
            'opens_at' => $data['opens_at'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'timezone' => $data['timezone'] ?? 'Asia/Qatar',
            'retention_days' => $data['retention_days'] ?? config('tedc.surveys.retention_days'),
        ]);
        SurveyEvents::record($survey, 'created', ['privacy_mode' => $survey->privacy_mode], $owner);

        return $survey;
    }

    /** Edits the draft. The privacy mode is frozen once anything was submitted. */
    public function update(Survey $survey, array $data, User $actor): Survey
    {
        if ($survey->status === 'archived') {
            throw new BusinessRuleException(__('messages.surveys.archived'), 'survey_archived');
        }
        $changes = [];
        foreach (['title_ar', 'title_en', 'description_ar', 'description_en', 'program_id', 'opens_at', 'closes_at', 'timezone', 'retention_days'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = in_array($field, ['title_en', 'description_ar', 'description_en'], true) ? SurveyDefinition::text($data[$field], $field === 'title_en' ? 200 : 2000) : $data[$field];
            }
        }
        if (isset($data['kind']) && in_array($data['kind'], Survey::KINDS, true)) {
            $changes['kind'] = $data['kind'];
        }
        $this->assertWindow($changes['opens_at'] ?? $survey->opens_at, $changes['closes_at'] ?? $survey->closes_at);
        if (array_key_exists('questions', $data)) {
            $changes['questions'] = SurveyDefinition::normalize($data['questions']);
        }
        if (array_key_exists('audience', $data)) {
            $changes['audience'] = $data['audience'] ? SurveyAudience::clean($data['audience']) : null;
        }
        if (array_key_exists('settings', $data)) {
            $changes['settings'] = SurveyDefinition::normalizeSettings((array) $data['settings'], $survey);
        }
        if (isset($data['privacy_mode']) && $data['privacy_mode'] !== $survey->privacy_mode) {
            if (! in_array($data['privacy_mode'], Survey::PRIVACY_MODES, true)) {
                throw new BusinessRuleException(__('messages.surveys.privacy_invalid'), 'privacy_invalid');
            }
            if ($survey->submissions()->exists() || $survey->participants()->whereNotNull('responded_at')->exists()) {
                throw new BusinessRuleException(__('messages.surveys.privacy_frozen'), 'privacy_frozen');
            }
            $changes['privacy_mode'] = $data['privacy_mode'];
            SurveyEvents::record($survey, 'privacy_changed', ['from' => $survey->privacy_mode, 'to' => $data['privacy_mode']], $actor);
        }
        // A survey sent back from review, or edited while waiting, returns to the draft state.
        if ($survey->status === 'in_review' && (isset($changes['questions']) || isset($changes['settings']))) {
            $changes['status'] = 'draft';
        }
        $survey->update($changes);
        SurveyEvents::record($survey, 'updated', ['fields' => array_keys($changes)], $actor);

        return $survey->refresh();
    }

    public function submitForReview(Survey $survey, User $actor): Survey
    {
        $this->assertStatus($survey, ['draft']);
        $this->assertAnswerable($survey);
        $survey->update(['status' => 'in_review', 'review_note' => null]);
        SurveyEvents::record($survey, 'submitted_for_review', [], $actor);

        return $survey;
    }

    public function approve(Survey $survey, User $actor, ?string $note = null): Survey
    {
        $this->assertStatus($survey, ['draft', 'in_review', 'approved', 'published', 'closed']);
        $this->assertAnswerable($survey);
        $survey->update([
            'approved_checksum' => SurveyDefinition::checksum($survey->questions),
            'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => SurveyDefinition::text($note, 1000),
            'status' => in_array($survey->status, ['draft', 'in_review'], true) ? 'approved' : $survey->status,
        ]);
        SurveyEvents::record($survey, 'approved', ['checksum' => $survey->approved_checksum], $actor);

        return $survey;
    }

    public function returnToDraft(Survey $survey, User $actor, ?string $note = null): Survey
    {
        $this->assertStatus($survey, ['in_review', 'approved']);
        $survey->update(['status' => 'draft', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => SurveyDefinition::text($note, 1000), 'approved_checksum' => null]);
        SurveyEvents::record($survey, 'returned', ['note' => $survey->review_note], $actor);

        return $survey;
    }

    /** Whether the current draft differs from the version respondents see. */
    public function hasUnpublishedChanges(Survey $survey): bool
    {
        $current = $survey->currentVersion;

        return ! $current || $current->checksum !== SurveyDefinition::checksum($survey->questions);
    }

    public function isApproved(Survey $survey): bool
    {
        return $survey->approved_checksum !== null && $survey->approved_checksum === SurveyDefinition::checksum($survey->questions);
    }

    /**
     * Publishes the draft: freezes a new version when the questions changed, enrols the audience and notifies
     * the newcomers. Returns the version number and how many people were added.
     *
     * @return array{version: int, added: int, participants: int}
     */
    public function publish(Survey $survey, User $actor): array
    {
        $this->assertStatus($survey, ['draft', 'in_review', 'approved', 'published', 'closed']);
        $this->assertAnswerable($survey);
        if ($survey->setting('requires_approval', true) && ! $this->isApproved($survey)) {
            if (! $actor->hasPermission('surveys.approve')) {
                throw new BusinessRuleException(__('messages.surveys.not_approved'), 'survey_not_approved');
            }
            $this->approve($survey, $actor, __('messages.surveys.self_approved'));
        }

        return DB::transaction(function () use ($survey, $actor) {
            $checksum = SurveyDefinition::checksum($survey->questions);
            $version = $survey->currentVersion;
            if (! $version || $version->checksum !== $checksum) {
                $version = SurveyVersion::create([
                    'survey_id' => $survey->id, 'number' => (int) $survey->versions()->max('number') + 1,
                    'questions' => $survey->questions, 'settings' => $this->respondentSettings($survey), 'checksum' => $checksum,
                    'published_by' => $actor->id, 'published_at' => now(),
                ]);
            }
            $survey->update([
                'status' => 'published', 'current_version_id' => $version->id, 'published_at' => $survey->published_at ?? now(), 'closed_at' => null,
                'pseudonym_key' => $survey->pseudonym_key ?: SurveyPrivacy::newKey(),
            ]);

            $added = $this->enrol($survey);
            SurveyEvents::record($survey, 'published', ['version' => $version->number, 'added' => $added], $actor);

            return ['version' => $version->number, 'added' => $added, 'participants' => $survey->participants()->count()];
        });
    }

    /** Adds the employees matching the audience to the participation registry and notifies the new ones. */
    public function enrol(Survey $survey): int
    {
        if ($survey->audience === null) {
            return 0;
        }
        $existing = $survey->participants()->pluck('user_id')->flip();
        $now = now();
        $new = [];
        $this->audience->query($survey->audience)->select('employees.id', 'employees.user_id')->orderBy('employees.id')
            ->chunk(1000, function ($chunk) use (&$new, $existing, $survey, $now) {
                $rows = $chunk->reject(fn ($e) => $existing->has($e->user_id))->map(fn ($e) => [
                    'id' => (string) Str::uuid(), 'survey_id' => $survey->id, 'user_id' => $e->user_id,
                    'invited_at' => $now, 'notified_at' => $survey->setting('notify', true) ? $now : null, 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all();
                if ($rows) {
                    SurveyParticipant::insertOrIgnore($rows);
                    array_push($new, ...array_column($rows, 'user_id'));
                }
            });

        if ($new && $survey->setting('notify', true)) {
            $this->notifications->broadcast($new, 'survey.invite',
                ['ar' => 'استبانة جديدة: '.$survey->title_ar, 'en' => 'New survey: '.($survey->title_en ?: $survey->title_ar)],
                ['ar' => 'نرجو مشاركتك، لن تستغرق إلا دقائق.', 'en' => 'Your answers take only a few minutes.'],
                ['survey_id' => $survey->id, 'link' => '/portal/surveys/'.$survey->id],
            );
        }

        return count($new);
    }

    public function remind(Survey $survey, User $actor): int
    {
        if (! $survey->isOpen()) {
            throw new BusinessRuleException(__('messages.surveys.not_open'), 'survey_not_open');
        }
        $pending = $survey->participants()->whereNull('responded_at')->pluck('user_id');
        if ($pending->isNotEmpty()) {
            $this->notifications->broadcast($pending, 'survey.reminder',
                ['ar' => 'تذكير: '.$survey->title_ar, 'en' => 'Reminder: '.($survey->title_en ?: $survey->title_ar)],
                ['ar' => 'لم نستلم إجاباتك بعد.', 'en' => 'We have not received your answers yet.'],
                ['survey_id' => $survey->id, 'link' => '/portal/surveys/'.$survey->id],
            );
            $survey->participants()->whereNull('responded_at')->update(['reminded_at' => now()]);
        }
        SurveyEvents::record($survey, 'reminded', ['count' => $pending->count()], $actor);

        return $pending->count();
    }

    public function close(Survey $survey, User $actor): Survey
    {
        $this->assertStatus($survey, ['published']);
        $survey->update(['status' => 'closed', 'closed_at' => now()]);
        SurveyEvents::record($survey, 'closed', ['submissions' => $survey->submissions()->count()], $actor);

        return $survey;
    }

    public function reopen(Survey $survey, User $actor): Survey
    {
        $this->assertStatus($survey, ['closed']);
        if ($this->hasUnpublishedChanges($survey)) {
            // Changed questions need a proper publish (new version, approval rules).
            throw new BusinessRuleException(__('messages.surveys.reopen_changed'), 'survey_reopen_changed');
        }
        $survey->update(['status' => 'published', 'closed_at' => null, 'closes_at' => $survey->closes_at?->isPast() ? null : $survey->closes_at]);
        SurveyEvents::record($survey, 'reopened', [], $actor);

        return $survey;
    }

    public function archive(Survey $survey, User $actor): Survey
    {
        $this->assertStatus($survey, ['draft', 'in_review', 'approved', 'closed']);
        $survey->update(['status' => 'archived', 'archived_at' => now()]);
        SurveyEvents::record($survey, 'archived', [], $actor);

        return $survey;
    }

    public function duplicate(Survey $survey, User $actor): Survey
    {
        $copy = $survey->replicate(['status', 'current_version_id', 'approved_checksum', 'review_note', 'reviewed_by', 'reviewed_at', 'pseudonym_key', 'published_at', 'closed_at', 'archived_at', 'legacy_type', 'legacy_id']);
        $copy->title_ar = Str::limit($survey->title_ar.' (نسخة)', 200, '');
        $copy->title_en = $survey->title_en ? Str::limit($survey->title_en.' (copy)', 200, '') : null;
        $copy->status = 'draft';
        $copy->owner_id = $actor->id;
        $copy->save();
        SurveyEvents::record($copy, 'duplicated', ['from' => $survey->id], $actor);

        return $copy;
    }

    /** The settings a respondent's client needs, frozen with the version. */
    public function respondentSettings(Survey $survey): array
    {
        return array_intersect_key($survey->settings ?? [], array_flip(['one_per_page', 'progress', 'welcome', 'welcome_en', 'thank_you', 'thank_you_en', 'accent', 'edit_policy']));
    }

    private function assertWindow(mixed $opensAt, mixed $closesAt): void
    {
        if ($opensAt && $closesAt && strtotime((string) $closesAt) <= strtotime((string) $opensAt)) {
            throw new BusinessRuleException(__('messages.surveys.window_invalid'), 'survey_window_invalid');
        }
    }

    private function assertStatus(Survey $survey, array $allowed): void
    {
        if (! in_array($survey->status, $allowed, true)) {
            throw new BusinessRuleException(__('messages.surveys.bad_transition', ['status' => $survey->status]), 'survey_bad_transition', ['status' => $survey->status, 'allowed' => $allowed]);
        }
    }

    private function assertAnswerable(Survey $survey): void
    {
        if (! array_filter($survey->questions ?? [], fn ($q) => ! in_array($q['type'], SurveyDefinition::NO_ANSWER, true))) {
            throw new BusinessRuleException(__('messages.surveys.no_questions'), 'survey_no_questions');
        }
    }
}
