<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\NeedsSurvey;
use App\Models\NeedsSurveyResponse;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyMigration;
use App\Models\SurveyParticipant;
use App\Models\SurveySubmission;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Survey Studio v2, Gate 5: moves the legacy needs surveys and evaluation forms onto the engine.
 *
 * A legacy row is never deleted: it keeps its data and points at the engine survey that took it over (`survey_id`),
 * after which it is read-only and the studio owns the questions, the versions, the approval and the answers. People
 * become participants, answers become submissions bound to a frozen version (evaluation answers keep their
 * assignment as the submission's context, so one person may answer the same form once per assignment), evidence
 * files become engine files pointing at the same stored object, and links stay a first-class answer. Every run is a
 * batch in the ledger and can be rolled back as long as no answer arrived through the engine since.
 */
class SurveyMigrator
{
    public function __construct(private readonly SurveyWorkflow $workflow, private readonly FileStorage $storage) {}

    // Planning ------------------------------------------------------------------------------------------------------

    /** What a run would do, without touching anything. */
    public function plan(): array
    {
        $needs = NeedsSurvey::whereNull('survey_id')->withCount(['recipients', 'responses'])->get();
        $forms = EvaluationForm::whereNull('survey_id')->where('is_system', false)->get();
        $formIds = $forms->pluck('id');
        $warnings = [];
        foreach ($needs as $s) {
            if (! array_filter($s->questions ?? [], fn ($q) => ($q['type'] ?? '') !== 'section')) {
                $warnings[] = ['type' => 'needs_survey', 'id' => $s->id, 'title' => $s->title, 'issue' => 'no_questions'];
            }
        }
        foreach ($forms as $f) {
            if (! $f->questions) {
                $warnings[] = ['type' => 'evaluation_form', 'id' => $f->id, 'title' => $f->title_ar, 'issue' => 'no_questions'];
            }
        }
        $orphans = NeedsSurveyResponse::whereIn('survey_id', $needs->pluck('id'))->whereNull('user_id')->count();
        if ($orphans) {
            $warnings[] = ['type' => 'needs_survey', 'id' => '*', 'title' => '', 'issue' => 'orphan_responses:'.$orphans];
        }

        return [
            'needs_surveys' => ['count' => $needs->count(), 'by_status' => $needs->countBy('status')->all(), 'recipients' => (int) $needs->sum('recipients_count'), 'responses' => (int) $needs->sum('responses_count')],
            'evaluation_forms' => ['count' => $forms->count(), 'assignments' => EvaluationAssignment::whereIn('form_id', $formIds)->count(),
                'responses' => EvaluationResponse::whereHas('assignment', fn ($q) => $q->whereIn('form_id', $formIds))->count(), 'system_skipped' => EvaluationForm::where('is_system', true)->count()],
            'migrated' => ['needs_surveys' => NeedsSurvey::whereNotNull('survey_id')->count(), 'evaluation_forms' => EvaluationForm::whereNotNull('survey_id')->count()],
            'warnings' => $warnings,
            'batches' => $this->batches(),
        ];
    }

    /** The batches in the ledger, newest first, with whether a rollback would lose engine-era answers. */
    public function batches(): array
    {
        return SurveyMigration::with('creator:id,name,name_ar')->orderByDesc('created_at')->get()->groupBy('batch')->map(function ($rows, $batch) {
            $ids = $rows->pluck('survey_id');

            return [
                'batch' => $batch, 'created_at' => $rows->min('created_at'), 'by' => $rows->first()->creator?->displayName(),
                'needs_surveys' => $rows->where('legacy_type', 'needs_survey')->count(), 'evaluation_forms' => $rows->where('legacy_type', 'evaluation_form')->count(),
                'surveys' => Survey::whereIn('id', $ids)->count(), 'late_answers' => SurveySubmission::whereIn('survey_id', $ids)->where('source', '!=', 'import')->count(),
            ];
        })->values()->all();
    }

    // Running -------------------------------------------------------------------------------------------------------

    /** Migrates everything pending (or only one family) as one batch. */
    public function run(string $only = 'all', ?User $by = null): array
    {
        $batch = now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
        $r = ['batch' => $batch, 'needs_surveys' => 0, 'evaluation_forms' => 0, 'participants' => 0, 'submissions' => 0, 'files' => 0, 'skipped' => []];
        $add = function (Survey $s) use (&$r) {
            $c = SurveyMigration::where('survey_id', $s->id)->first()?->counts ?? [];
            $r['participants'] += $c['participants'] ?? 0;
            $r['submissions'] += $c['submissions'] ?? 0;
            $r['files'] += $c['files'] ?? 0;
        };
        if (in_array($only, ['all', 'needs'], true)) {
            foreach (NeedsSurvey::whereNull('survey_id')->orderBy('created_at')->get() as $legacy) {
                try {
                    $add($this->migrateNeedsSurvey($legacy, $batch, $by));
                    $r['needs_surveys']++;
                } catch (ValidationException|BusinessRuleException $e) {
                    $r['skipped'][] = ['type' => 'needs_survey', 'id' => $legacy->id, 'title' => $legacy->title, 'reason' => $e->getMessage()];
                }
            }
        }
        if (in_array($only, ['all', 'evaluations'], true)) {
            foreach (EvaluationForm::whereNull('survey_id')->where('is_system', false)->orderBy('created_at')->get() as $form) {
                try {
                    $add($this->migrateForm($form, $batch, $by));
                    $r['evaluation_forms']++;
                } catch (ValidationException|BusinessRuleException $e) {
                    $r['skipped'][] = ['type' => 'evaluation_form', 'id' => $form->id, 'title' => $form->title_ar, 'reason' => $e->getMessage()];
                }
            }
        }
        $this->audit('survey.migration_run', null, $r, $by);

        return $r;
    }

    /** One needs survey, with its recipients and answers. */
    public function migrateNeedsSurvey(NeedsSurvey $legacy, ?string $batch = null, ?User $by = null): Survey
    {
        if ($legacy->survey_id) {
            throw new BusinessRuleException(__('messages.surveys.migrated'), 'survey_migrated', ['survey_id' => $legacy->survey_id]);
        }
        $batch ??= now()->format('Ymd-His').'-'.Str::lower(Str::random(4));

        return DB::transaction(function () use ($legacy, $batch, $by) {
            $settings = $legacy->settings ?? [];
            $anonymous = (bool) ($settings['anonymous'] ?? false);
            $questions = LegacyQuestions::convert($legacy->questions ?? [], []);
            $status = match ($legacy->status) {
                'published' => 'published', 'closed' => 'closed', default => 'draft'
            };
            $approved = $status !== 'draft' || $legacy->approval_status === 'approved';

            $survey = Survey::create([
                'kind' => 'needs', 'title_ar' => Str::limit($legacy->title ?: 'استبانة', 200, ''), 'description_ar' => $legacy->description,
                'status' => $status, 'privacy_mode' => $anonymous ? 'confidential' : 'identified', 'owner_id' => $legacy->created_by,
                'questions' => $questions, 'audience' => $legacy->audience ?: null,
                'settings' => SurveyDefinition::normalizeSettings([
                    'requires_approval' => true, 'edit_policy' => 'until_close', 'segment_fields' => SurveyPrivacy::SEGMENT_FIELDS, 'notify' => true,
                    'one_per_page' => (bool) ($settings['one_per_page'] ?? false), 'progress' => (bool) ($settings['show_progress'] ?? true),
                    'welcome' => $settings['welcome'] ?? null, 'thank_you' => $settings['thank_you'] ?? null, 'accent' => $settings['accent'] ?? null,
                ]),
                'approved_checksum' => $approved ? SurveyDefinition::checksum($questions) : null,
                'reviewed_by' => $legacy->approved_by, 'reviewed_at' => $legacy->approved_at, 'review_note' => $legacy->approval_note,
                'closes_at' => $legacy->closes_at, 'published_at' => $legacy->published_at, 'closed_at' => $legacy->closed_at,
                'retention_days' => config('tedc.surveys.retention_days'), 'pseudonym_key' => SurveyPrivacy::newKey(),
                'legacy_type' => 'needs_survey', 'legacy_id' => $legacy->id,
            ]);
            $counts = ['participants' => 0, 'submissions' => 0, 'files' => 0];

            if ($status !== 'draft') {
                $version = SurveyVersion::create([
                    'survey_id' => $survey->id, 'number' => 1, 'questions' => $questions, 'settings' => $this->workflow->respondentSettings($survey),
                    'checksum' => SurveyDefinition::checksum($questions), 'published_by' => $legacy->created_by, 'published_at' => $legacy->published_at ?? $legacy->created_at ?? now(),
                ]);
                $survey->update(['current_version_id' => $version->id]);

                $now = now();
                $legacy->recipients()->orderBy('created_at')->chunk(500, function ($chunk) use ($survey, $now, &$counts) {
                    $rows = $chunk->map(fn ($r) => [
                        'id' => (string) Str::uuid(), 'survey_id' => $survey->id, 'user_id' => $r->user_id, 'invited_at' => $r->created_at ?? $now,
                        'notified_at' => $r->notified_at, 'reminded_at' => $r->reminded_at, 'responded_at' => $r->responded_at, 'created_at' => $r->created_at ?? $now, 'updated_at' => $now,
                    ])->all();
                    $counts['participants'] += SurveyParticipant::insertOrIgnore($rows);
                });

                $users = User::whereIn('id', $legacy->responses()->whereNotNull('user_id')->pluck('user_id'))->get()->keyBy('id');
                foreach ($legacy->responses()->orderBy('submitted_at')->cursor() as $r) {
                    $user = $r->user_id ? $users->get($r->user_id) : null;
                    SurveySubmission::create([
                        'survey_id' => $survey->id, 'version_id' => $version->id,
                        'respondent_user_id' => $anonymous ? null : $r->user_id,
                        'respondent_pseudonym' => $anonymous ? ($user ? SurveyPrivacy::pseudonym($survey, $user) : hash('sha256', 'legacy:'.$r->id)) : null,
                        'segment_snapshot' => $this->legacySnapshot($r), 'answers' => $r->answers ?? [], 'status' => 'submitted', 'source' => 'import',
                        'duration_seconds' => $r->duration_seconds, 'submitted_at' => $r->submitted_at ?? $r->created_at ?? now(),
                    ]);
                    $counts['submissions']++;
                }
            }

            $legacy->forceFill(['survey_id' => $survey->id])->save();
            SurveyMigration::create(['batch' => $batch, 'legacy_type' => 'needs_survey', 'legacy_id' => $legacy->id, 'survey_id' => $survey->id, 'counts' => $counts, 'created_by' => $by?->id, 'created_at' => now()]);
            SurveyEvents::record($survey, 'migrated', ['from' => 'needs_survey', 'legacy_id' => $legacy->id, 'batch' => $batch] + $counts, $by);

            return $survey;
        });
    }

    /** One evaluation form, with its assignments and answers (evidence included). */
    public function migrateForm(EvaluationForm $form, ?string $batch = null, ?User $by = null): Survey
    {
        if ($form->is_system) {
            throw new BusinessRuleException(__('messages.evaluation.system_form'), 'system_form');
        }
        if ($form->survey_id) {
            throw new BusinessRuleException(__('messages.evaluation.form_migrated'), 'form_migrated', ['survey_id' => $form->survey_id]);
        }
        $batch ??= now()->format('Ymd-His').'-'.Str::lower(Str::random(4));

        return DB::transaction(function () use ($form, $batch, $by) {
            $settings = $form->settings ?? [];
            $questions = LegacyQuestions::convert($form->questions ?? [], $settings);
            $approved = $form->approval_status === 'approved';

            $survey = Survey::create([
                'kind' => in_array($form->kind, Survey::KINDS, true) ? $form->kind : 'custom', 'title_ar' => $form->title_ar, 'title_en' => $form->title_en,
                'status' => $approved ? 'published' : ($form->approval_status === 'pending' ? 'in_review' : 'draft'),
                'privacy_mode' => ! empty($settings['anonymous']) ? 'confidential' : 'identified', 'owner_id' => $form->created_by,
                'questions' => $questions, 'audience' => null,
                'settings' => SurveyDefinition::normalizeSettings(['requires_approval' => true, 'edit_policy' => 'never', 'notify' => false, 'segment_fields' => SurveyPrivacy::SEGMENT_FIELDS, 'progress' => true]),
                'approved_checksum' => $approved ? SurveyDefinition::checksum($questions) : null,
                'reviewed_by' => $form->approved_by, 'reviewed_at' => $form->approved_at, 'review_note' => $form->approval_note,
                'published_at' => $approved ? ($form->approved_at ?? now()) : null,
                'retention_days' => config('tedc.surveys.retention_days'), 'pseudonym_key' => SurveyPrivacy::newKey(),
                'legacy_type' => 'evaluation_form', 'legacy_id' => $form->id,
            ]);
            $counts = ['participants' => 0, 'submissions' => 0, 'files' => 0];
            $version = null;
            if ($approved) {
                $version = SurveyVersion::create([
                    'survey_id' => $survey->id, 'number' => max(1, (int) $form->version), 'questions' => $questions, 'settings' => $this->workflow->respondentSettings($survey),
                    'checksum' => SurveyDefinition::checksum($questions), 'published_by' => $form->approved_by, 'published_at' => $form->approved_at ?? now(),
                ]);
                $survey->update(['current_version_id' => $version->id]);
            }

            foreach (EvaluationAssignment::with('response', 'respondent.employee.school')->where('form_id', $form->id)->orderBy('created_at')->get() as $a) {
                $patch = ['survey_id' => $survey->id];
                if ($a->response && $version) {
                    $user = $a->respondent;
                    $key = $user ? SurveyPrivacy::contextKey($survey, $user, $a->id) : hash('sha256', 'legacy:'.$a->id);
                    [$answers, $fileRows] = $this->legacyEvidence($a->response, $questions);
                    $submission = SurveySubmission::create([
                        'survey_id' => $survey->id, 'version_id' => $version->id, 'context_id' => $a->id,
                        'respondent_user_id' => $survey->isIdentified() ? $a->respondent_user_id : null, 'respondent_pseudonym' => $survey->isIdentified() ? null : $key,
                        'segment_snapshot' => $user?->employee ? SurveyPrivacy::segmentSnapshot($survey, $user->employee) : null,
                        'answers' => $answers, 'status' => 'submitted', 'source' => 'import', 'submitted_at' => $a->response->submitted_at ?? now(),
                    ]);
                    foreach ($fileRows as $f) {
                        (new SurveyFile)->forceFill($f + ['survey_id' => $survey->id, 'respondent_key' => $key, 'submission_id' => $submission->id])->save();
                        $counts['files']++;
                    }
                    $patch += ['submission_id' => $submission->id, 'score' => $a->response->score];
                    $counts['submissions']++;
                }
                $a->update($patch);
                $counts['participants']++;
            }

            $form->forceFill(['survey_id' => $survey->id])->save();
            SurveyMigration::create(['batch' => $batch, 'legacy_type' => 'evaluation_form', 'legacy_id' => $form->id, 'survey_id' => $survey->id, 'counts' => $counts, 'created_by' => $by?->id, 'created_at' => now()]);
            SurveyEvents::record($survey, 'migrated', ['from' => 'evaluation_form', 'legacy_id' => $form->id, 'batch' => $batch] + $counts, $by);

            return $survey;
        });
    }

    // Rolling back --------------------------------------------------------------------------------------------------

    /**
     * Removes the engine surveys of a batch and hands the legacy rows back. Refused while answers that arrived through
     * the engine exist (they have no legacy copy), unless forced.
     */
    public function rollback(string $batch, bool $force = false, ?User $by = null): array
    {
        $rows = SurveyMigration::where('batch', $batch)->get();
        abort_if($rows->isEmpty(), 404, __('messages.surveys.batch_unknown'));
        $ids = $rows->pluck('survey_id');
        $late = SurveySubmission::whereIn('survey_id', $ids)->where('source', '!=', 'import')->count();
        if ($late && ! $force) {
            throw new BusinessRuleException(__('messages.surveys.rollback_blocked', ['n' => $late]), 'survey_rollback_blocked', ['late' => $late]);
        }

        return DB::transaction(function () use ($rows, $ids, $batch, $late, $by) {
            $r = ['batch' => $batch, 'surveys' => 0, 'needs_surveys' => 0, 'evaluation_forms' => 0, 'late_answers_lost' => $late];
            $r['evaluation_forms'] = EvaluationForm::whereIn('survey_id', $ids)->update(['survey_id' => null]);
            EvaluationAssignment::whereIn('survey_id', $ids)->update(['survey_id' => null, 'submission_id' => null, 'score' => null]);
            $r['needs_surveys'] = NeedsSurvey::whereIn('survey_id', $ids)->update(['survey_id' => null]);
            // Files uploaded through the engine since the migration have no legacy copy: drop their stored objects.
            foreach (SurveyFile::whereIn('survey_id', $ids)->where('path', 'like', 'surveys/%')->get() as $f) {
                rescue(fn () => $this->storage->delete($f->bucket, $f->path), report: false);
            }
            foreach (Survey::whereIn('id', $ids)->get() as $s) {
                $s->forceDelete();
                $r['surveys']++;
            }
            SurveyMigration::where('batch', $batch)->delete();
            $this->audit('survey.migration_rollback', null, $r + ['legacy' => $rows->map(fn ($m) => [$m->legacy_type, $m->legacy_id])->all()], $by);

            return $r;
        });
    }

    // Helpers -------------------------------------------------------------------------------------------------------

    /** The segment snapshot a legacy needs-survey answer carried, in the engine's field names. */
    private function legacySnapshot(NeedsSurveyResponse $r): ?array
    {
        $out = array_filter([
            'school_id' => $r->school_id, 'job_title_id' => $r->job_title_id, 'education_stage' => $r->education_stage, 'specialization' => $r->specialization,
            'nationality' => $r->nationality, 'gender' => $r->gender, 'experience_band' => SurveyPrivacy::experienceBand($r->experience_years),
        ], fn ($v) => $v !== null && $v !== '');

        return $out ?: null;
    }

    /**
     * The legacy answers plus the evidence moved into the companions: files become engine file rows over the same
     * stored object, links join the link field.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function legacyEvidence(EvaluationResponse $response, array $questions): array
    {
        $answers = $response->answers ?? [];
        $files = [];
        $ids = array_column($questions, 'id');
        foreach ((array) ($response->evidence ?? []) as $qid => $items) {
            $base = LegacyQuestions::base((string) $qid);
            $fileQ = $base.LegacyQuestions::EVIDENCE_SUFFIX;
            $linkQ = $base.LegacyQuestions::LINK_SUFFIX;
            if (! in_array($fileQ, $ids, true)) {
                continue;
            }
            $fileIds = [];
            $links = [];
            foreach ((array) $items as $item) {
                if (($item['type'] ?? '') === 'file' && ! empty($item['path'])) {
                    $id = (string) Str::uuid();
                    $fileIds[] = $id;
                    $name = (string) ($item['name'] ?? basename($item['path']));
                    $files[] = ['id' => $id, 'question_id' => $fileQ, 'bucket' => 'evidence', 'path' => $item['path'], 'original_name' => Str::limit($name, 200, ''), 'mime' => LegacyQuestions::mimeOf($name), 'size' => 0];
                } elseif (($item['type'] ?? '') === 'link' && ! empty($item['url'])) {
                    $links[] = $item['url'];
                }
            }
            if ($fileIds) {
                $answers[$fileQ] = $fileIds;
            }
            if ($links) {
                $answers[$linkQ] = Str::limit(implode(' | ', $links), 500, '');
            }
        }

        return [$answers, $files];
    }

    private function audit(string $action, ?string $surveyId, array $data, ?User $by): void
    {
        AuditLog::create([
            'user_id' => $by?->id, 'action' => $action, 'auditable_type' => Survey::class, 'auditable_id' => $surveyId,
            'old_values' => null, 'new_values' => $data, 'ip_address' => Request::ip(), 'user_agent' => substr((string) Request::userAgent(), 0, 255), 'url' => substr((string) Request::fullUrl(), 0, 255),
        ]);
    }
}
