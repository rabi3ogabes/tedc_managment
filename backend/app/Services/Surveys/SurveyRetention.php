<?php

namespace App\Services\Surveys;

use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyInvitation;
use App\Models\SurveyParticipant;
use App\Models\SurveySession;
use App\Models\SurveySubmission;
use App\Security\SecurityEvents;
use App\Services\FileStorage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Survey Studio v2, Gate 7: what the engine keeps and for how long, applied every day.
 *
 *  drafts        autosaved drafts past their TTL, and uploaded files never bound to an answer.
 *  invitations   personal invitations (an e-mail address and a token) a set number of days after the survey closed.
 *  keys          the pseudonym key of a closed survey after the grace period: confidential answers can then never be
 *                matched to a person again, and drafts filed under pseudonymous keys go with it.
 *  answers       the answers themselves after the survey's retention period: the summary and per-question statistics
 *                are frozen on the survey, then submissions, edits, files, drafts, participants and invitations are
 *                deleted. A legal hold stops this for one survey.
 */
class SurveyRetention
{
    public function __construct(private readonly FileStorage $storage, private readonly SurveyAnalytics $analytics) {}

    public function policy(): array
    {
        return [
            'retention_days' => (int) config('tedc.surveys.retention_days', 1095),
            'invitation_retention_days' => (int) config('tedc.surveys.invitation_retention_days', 90),
            'draft_ttl_days' => (int) config('tedc.surveys.draft_ttl_days', 30),
            'key_grace_days' => (int) config('tedc.surveys.key_grace_days', 30),
        ];
    }

    /** What the next run would do, and when the next surveys fall due. */
    public function report(): array
    {
        $p = $this->policy();
        $due = $this->dueForRetirement()->get();
        $upcoming = Survey::whereNull('answers_purged_at')->where('legal_hold', false)->whereIn('status', ['closed', 'archived'])
            ->get()->map(fn (Survey $s) => ['id' => $s->id, 'title' => $s->title(), 'retire_at' => $this->retireAt($s)])->filter(fn ($r) => $r['retire_at'] && $r['retire_at']->isFuture())
            ->sortBy('retire_at')->take(10)->values()->all();

        return [
            'policy' => $p,
            'pending' => [
                'drafts' => SurveySession::where('expires_at', '<', now())->count(),
                'orphan_files' => $this->orphanFiles()->count(),
                'invitations' => $this->invitationsToPurge()->count(),
                'keys' => $this->keysToDrop()->count(),
                'answers' => $due->count(),
            ],
            'due' => $due->map(fn (Survey $s) => ['id' => $s->id, 'title' => $s->title(), 'closed_at' => $s->closed_at ?? $s->archived_at, 'submissions' => $s->submissions()->count()])->values()->all(),
            'upcoming' => $upcoming,
            'held' => Survey::where('legal_hold', true)->get(['id', 'title_ar', 'title_en'])->map(fn ($s) => ['id' => $s->id, 'title' => $s->title()])->all(),
            'purged' => Survey::whereNotNull('answers_purged_at')->count(),
            'keys_deleted' => Survey::whereNotNull('key_deleted_at')->count(),
        ];
    }

    /** Runs every job; with `$dry` nothing is changed and the counts say what would happen. */
    public function run(bool $dry = false): array
    {
        $r = ['dry_run' => $dry, 'drafts' => 0, 'orphan_files' => 0, 'invitations' => 0, 'keys' => 0, 'answers' => 0, 'retired' => []];
        $r['drafts'] = $dry ? SurveySession::where('expires_at', '<', now())->count() : SurveySession::where('expires_at', '<', now())->delete();
        $orphans = $this->orphanFiles()->get();
        $r['orphan_files'] = $orphans->count();
        if (! $dry) {
            foreach ($orphans as $f) {
                rescue(fn () => $this->storage->delete($f->bucket, $f->path), report: false);
                $f->delete();
            }
        }
        $r['invitations'] = $dry ? $this->invitationsToPurge()->count() : $this->invitationsToPurge()->delete();
        foreach ($this->keysToDrop()->get() as $survey) {
            $r['keys']++;
            if (! $dry) {
                $this->dropKey($survey);
            }
        }
        foreach ($this->dueForRetirement()->get() as $survey) {
            $r['answers']++;
            $r['retired'][] = ['id' => $survey->id, 'title' => $survey->title()];
            if (! $dry) {
                $this->retire($survey);
            }
        }

        return $r;
    }

    // Keys -----------------------------------------------------------------------------------------------------------

    public function dropKey(Survey $survey): void
    {
        DB::transaction(function () use ($survey) {
            // Drafts filed under pseudonymous keys can never be reopened without the key.
            if (! $survey->isIdentified()) {
                $survey->sessions()->delete();
            }
            $survey->forceFill(['pseudonym_key' => null, 'key_deleted_at' => now()])->save();
            SurveyEvents::record($survey, 'key_deleted', ['grace_days' => $this->policy()['key_grace_days']]);
        });
        SecurityEvents::record('survey_key_deleted', null, 'ok', ['survey_id' => $survey->id]);
    }

    // Answers --------------------------------------------------------------------------------------------------------

    /** Freezes the aggregates, then deletes every answer and every trace of who was asked. */
    public function retire(Survey $survey): array
    {
        $counts = ['submissions' => 0, 'files' => 0, 'participants' => 0, 'invitations' => 0, 'drafts' => 0];
        $results = $this->analytics->results($survey, [], null, false, 'ar');
        $aggregates = ['frozen_at' => now()->toIso8601String(), 'summary' => $results['summary'], 'questions' => $results['questions'], 'needs' => $results['needs']];
        DB::transaction(function () use ($survey, $aggregates, &$counts) {
            foreach (SurveyFile::where('survey_id', $survey->id)->get() as $f) {
                rescue(fn () => $this->storage->delete($f->bucket, $f->path), report: false);
                $f->delete();
                $counts['files']++;
            }
            $counts['submissions'] = SurveySubmission::where('survey_id', $survey->id)->delete();
            $counts['drafts'] = $survey->sessions()->delete();
            $counts['participants'] = SurveyParticipant::where('survey_id', $survey->id)->delete();
            $counts['invitations'] = SurveyInvitation::where('survey_id', $survey->id)->delete();
            $survey->distributions()->update(['is_active' => false, 'revoked_at' => now()]);
            $survey->forceFill(['aggregates' => $aggregates, 'answers_purged_at' => now(), 'pseudonym_key' => null, 'key_deleted_at' => $survey->key_deleted_at ?? now()])->save();
            SurveyEvents::record($survey, 'answers_purged', $counts + ['retention_days' => $survey->retention_days ?? $this->policy()['retention_days']]);
        });
        SecurityEvents::record('survey_answers_purged', null, 'ok', ['survey_id' => $survey->id] + $counts);

        return $counts;
    }

    /** When a closed survey's answers fall due, by its own retention period (null while it is open or held). */
    public function retireAt(Survey $survey): ?CarbonInterface
    {
        $closed = $survey->closed_at ?? $survey->archived_at;
        if (! $closed || $survey->legal_hold || $survey->answers_purged_at || ! in_array($survey->status, ['closed', 'archived'], true)) {
            return null;
        }

        return $closed->copy()->addDays($survey->retention_days ?? $this->policy()['retention_days']);
    }

    // Queries --------------------------------------------------------------------------------------------------------

    private function dueForRetirement()
    {
        $p = $this->policy();

        return Survey::whereNull('answers_purged_at')->where('legal_hold', false)->whereIn('status', ['closed', 'archived'])
            ->where(function ($q) use ($p) {
                $q->whereRaw('coalesce(closed_at, archived_at) < ?', [now()->subDays($p['retention_days'])])
                    ->orWhere(fn ($w) => $w->whereNotNull('retention_days')->whereRaw('coalesce(closed_at, archived_at) < ?', [now()]));
            })
            ->get()->filter(fn (Survey $s) => ($at = $this->retireAt($s)) && $at->isPast())->pipe(fn ($c) => Survey::whereIn('id', $c->pluck('id')));
    }

    private function keysToDrop()
    {
        return Survey::whereNotNull('pseudonym_key')->whereNull('key_deleted_at')->whereIn('status', ['closed', 'archived'])
            ->whereRaw('coalesce(closed_at, archived_at) < ?', [now()->subDays($this->policy()['key_grace_days'])]);
    }

    private function invitationsToPurge()
    {
        return SurveyInvitation::whereIn('survey_id', Survey::whereIn('status', ['closed', 'archived'])->whereRaw('coalesce(closed_at, archived_at) < ?', [now()->subDays($this->policy()['invitation_retention_days'])])->select('id'));
    }

    private function orphanFiles()
    {
        return SurveyFile::whereNull('submission_id')->where('created_at', '<', now()->subDays($this->policy()['draft_ttl_days']));
    }
}
