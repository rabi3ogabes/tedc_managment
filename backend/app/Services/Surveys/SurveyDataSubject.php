<?php

namespace App\Services\Surveys;

use App\Models\AuditLog;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyParticipant;
use App\Models\SurveySession;
use App\Models\SurveySubmission;
use App\Models\User;
use App\Security\SecurityEvents;
use App\Services\FileStorage;
use Illuminate\Support\Facades\DB;

/**
 * Survey Studio v2, Gate 7: a person's rights over their survey data. Access gives the surveys they were asked to
 * answer and, for identified surveys, their answers with the question titles. Erasure detaches the person from
 * every identified answer (the answer stays, anonymous, so the statistics hold), deletes their files, drafts and
 * participation rows. Confidential and anonymous answers cannot be linked to a person by design, which is said plainly.
 */
class SurveyDataSubject
{
    public function __construct(private readonly FileStorage $storage) {}

    public function export(User $user): array
    {
        $participations = SurveyParticipant::with('survey:id,title_ar,title_en,privacy_mode,status,closes_at')->where('user_id', $user->id)->get()
            ->map(fn ($p) => ['survey' => $p->survey?->title(), 'privacy_mode' => $p->survey?->privacy_mode, 'invited_at' => $p->invited_at, 'responded_at' => $p->responded_at])->values()->all();
        $answers = [];
        foreach (SurveySubmission::with('survey:id,title_ar,title_en,kind,privacy_mode', 'version:id,questions')->where('respondent_user_id', $user->id)->orderBy('submitted_at')->get() as $s) {
            $titles = collect($s->version?->questions ?? [])->pluck('title', 'id');
            $answers[] = [
                'survey' => $s->survey?->title(), 'kind' => $s->survey?->kind, 'context' => $s->context_id ? 'evaluation_assignment' : null, 'submitted_at' => $s->submitted_at, 'edited_at' => $s->edited_at, 'source' => $s->source,
                'segment' => $s->segment_snapshot,
                'answers' => collect($s->answers)->mapWithKeys(fn ($v, $k) => [(string) ($titles[$k] ?? $k) => $v])->all(),
            ];
        }

        return [
            'participations' => $participations,
            'identified_answers' => $answers,
            'note' => 'Answers to confidential and anonymous surveys carry no identity and cannot be linked to you; they are not listed. / إجابات الاستبانات السرية والمجهولة لا تحمل هوية ولا يمكن ربطها بك، فلا تُدرج.',
        ];
    }

    /** What an erasure would touch. */
    public function preview(User $user): array
    {
        $subs = SurveySubmission::where('respondent_user_id', $user->id);

        return [
            'identified_answers' => (clone $subs)->count(),
            'files' => SurveyFile::whereIn('submission_id', (clone $subs)->select('id'))->count(),
            'participations' => SurveyParticipant::where('user_id', $user->id)->count(),
            'drafts' => SurveySession::where('respondent_key', $user->id)->count(),
        ];
    }

    /** Detaches the person from every identified answer and removes their files, drafts and participation rows. */
    public function erase(User $user, ?User $by = null): array
    {
        $counts = $this->preview($user);
        DB::transaction(function () use ($user, $by, $counts) {
            $subs = SurveySubmission::where('respondent_user_id', $user->id)->get();
            foreach (SurveyFile::whereIn('submission_id', $subs->pluck('id'))->get() as $f) {
                rescue(fn () => $this->storage->delete($f->bucket, $f->path), report: false);
                $f->delete();
            }
            // The answers stay, anonymous: the file answers they pointed at are gone, so those keys are cleared too.
            foreach ($subs as $s) {
                $answers = $s->answers;
                foreach (collect($s->version?->questions ?? [])->where('type', 'file_upload')->pluck('id') as $qid) {
                    unset($answers[$qid]);
                }
                $s->forceFill(['respondent_user_id' => null, 'segment_snapshot' => null, 'answers' => $answers])->save();
            }
            SurveySession::where('respondent_key', $user->id)->delete();
            SurveyParticipant::where('user_id', $user->id)->delete();
            foreach (Survey::whereIn('id', $subs->pluck('survey_id')->unique())->get() as $survey) {
                SurveyEvents::record($survey, 'subject_erased', ['answers' => $subs->where('survey_id', $survey->id)->count()], $by);
            }
            AuditLog::create(['user_id' => $by?->id, 'action' => 'survey_data_erased', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => $counts]);
        });
        SecurityEvents::record('survey_data_erased', $by, 'ok', ['subject' => $user->id] + $counts);

        return $counts;
    }
}
