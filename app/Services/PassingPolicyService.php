<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Attendance;
use App\Models\KnowledgeTransfer;
use App\Models\PassException;
use App\Models\PassingPolicy;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides whether a trainee has passed. The effective policy is the group's, else the program's, else the global one;
 * with none the old rule applies (every requirement of the program must be met), so programs nobody configured behave as before.
 */
class PassingPolicyService
{
    public const KEYS = ['attendance', 'participation', 'tasks', 'assessments', 'course', 'evaluation', 'knowledge_transfer'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function stored(?string $scope, ?string $scopeId): ?PassingPolicy
    {
        return PassingPolicy::where('scope', $scope)->where('scope_id', $scopeId)->first();
    }

    /** The policy row that applies to a registration, if anyone configured one. */
    public function effectiveRow(Registration $r): ?PassingPolicy
    {
        return ($r->training_group_id ? $this->stored('group', $r->training_group_id) : null)
            ?? $this->stored('program', $r->program_id)
            ?? $this->stored('global', null);
    }

    /**
     * The policy as a plain array, always complete.
     *
     * @return array<string, mixed>
     */
    public function policy(Registration $r, ?PassingPolicy $override = null): array
    {
        $r->loadMissing('program');
        $row = $override ?? $this->effectiveRow($r);
        if ($row) {
            return $this->normalise($row->toArray(), $r->program, $row->scope ?? 'draft');
        }

        $p = $r->program;
        $criteria = [['key' => 'attendance', 'required' => true, 'min' => (float) $p->min_attendance_percent, 'weight' => 0]];
        $p->requires_tasks && $criteria[] = ['key' => 'tasks', 'required' => true, 'min' => 100.0, 'weight' => 0];
        $p->requires_evaluation && $criteria[] = ['key' => 'evaluation', 'required' => true, 'min' => 100.0, 'weight' => 0];
        $p->has_course && $criteria[] = ['key' => 'course', 'required' => true, 'min' => (float) $p->course_completion_percent, 'weight' => 0];

        return $this->normalise(['mode' => 'all_required', 'criteria' => $criteria], $p, 'default');
    }

    /** @return array<string, mixed> */
    private function normalise(array $d, Program $program, string $source): array
    {
        $criteria = collect($d['criteria'] ?? [])->filter(fn ($c) => in_array($c['key'] ?? null, self::KEYS, true))->map(fn ($c) => [
            'key' => $c['key'], 'required' => (bool) ($c['required'] ?? true), 'weight' => (float) ($c['weight'] ?? 0),
            'min' => (float) ($c['min'] ?? ($c['key'] === 'attendance' ? $program->min_attendance_percent : ($c['key'] === 'course' ? $program->course_completion_percent : (in_array($c['key'], ['tasks', 'evaluation'], true) ? 100 : 60)))),
        ])->values()->all();

        return [
            'source' => $source, 'mode' => $d['mode'] ?? 'all_required', 'criteria' => $criteria, 'pass_threshold' => (float) ($d['pass_threshold'] ?? 60),
            'assessment_ids' => $d['assessment_ids'] ?? null, 'participation_rules' => ($d['participation_rules'] ?? null) ?: ['lessons' => true, 'session_marks' => true],
            'allow_test_out' => (bool) ($d['allow_test_out'] ?? false), 'test_out_assessment_id' => $d['test_out_assessment_id'] ?? null, 'hours_mode' => $d['hours_mode'] ?? 'total',
            'certificate_types' => $d['certificate_types'] ?? 'pass', 'attendance_certificate_min' => (float) ($d['attendance_certificate_min'] ?? 80),
            'survey_required_for_download' => (bool) ($d['survey_required_for_download'] ?? true), 'task_approval' => $d['task_approval'] ?? 'trainer', 'certificate_templates' => $d['certificate_templates'] ?? [],
        ];
    }

    /**
     * Evaluates every criterion with its evidence and decides the outcome.
     *
     * @return array{policy: array<string, mixed>, criteria: list<array<string, mixed>>, weighted_score: ?float, passed: bool, via: ?string, met_without_exceptions: bool, test_out_passed: bool}
     */
    public function evaluate(Registration $r, ?PassingPolicy $override = null): array
    {
        $r->loadMissing('program');
        $policy = $this->policy($r, $override);
        $exempt = PassException::where('registration_id', $r->id)->whereNull('revoked_at')->pluck('criterion')->all();
        $rows = [];
        foreach ($policy['criteria'] as $c) {
            $v = $this->value($r, $c['key'], $policy);
            $isExempt = in_array($c['key'], $exempt, true) && $v['applicable'];
            $met = ! $v['applicable'] || $isExempt || $c['min'] <= $v['value'] + 1e-9 || ($c['key'] === 'course' && $r->course_completed);
            $rows[] = $c + $v + ['met' => $met, 'exempted' => $isExempt, 'metRaw' => ! $v['applicable'] || $c['min'] <= $v['value'] + 1e-9 || ($c['key'] === 'course' && $r->course_completed)];
        }

        $score = $this->score($rows, $policy);
        $standard = $this->decide($rows, $policy, $score, false);
        $withEx = $this->decide($rows, $policy, $score, true);
        $testOut = $this->testOutPassed($r, $policy);
        $passed = $withEx || $testOut;
        $via = $testOut && ! $withEx ? 'test_out' : ($withEx && ! $standard ? 'exception' : ($withEx ? 'standard' : null));

        return ['policy' => $policy, 'criteria' => $rows, 'weighted_score' => $score, 'passed' => $passed, 'via' => $via, 'met_without_exceptions' => $standard, 'test_out_passed' => $testOut];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function score(array $rows, array $policy): ?float
    {
        $live = array_values(array_filter($rows, fn ($c) => $c['applicable']));
        if ($live === []) {
            return null;
        }
        $total = array_sum(array_column($live, 'weight'));
        if ($total <= 0) {
            return round(array_sum(array_map(fn ($c) => $c['exempted'] ? 100 : $c['value'], $live)) / count($live), 2);
        }

        return round(array_sum(array_map(fn ($c) => ($c['exempted'] ? 100 : $c['value']) * $c['weight'], $live)) / $total, 2);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function decide(array $rows, array $policy, ?float $score, bool $useExceptions): bool
    {
        $mins = true;
        foreach ($rows as $c) {
            if (! $c['applicable']) {
                continue;
            }
            $ok = $useExceptions ? $c['met'] : $c['metRaw'];
            // Weighted: only the criteria marked required have a hard minimum. All-required: every one has.
            if (($policy['mode'] === 'all_required' || $c['required']) && ! $ok) {
                $mins = false;
            }
        }
        if (! $mins) {
            return false;
        }
        if ($policy['mode'] === 'weighted') {
            if ($score === null) {
                return false;
            }
            if (! $useExceptions) {
                $score = $this->score(array_map(fn ($c) => ['exempted' => false] + $c, $rows), $policy);
            }

            return $score !== null && $score + 1e-9 >= $policy['pass_threshold'];
        }

        return true;
    }

    /** @return array{value: float, applicable: bool, evidence: array<string, mixed>} */
    private function value(Registration $r, string $key, array $policy): array
    {
        $p = $r->program;

        return match ($key) {
            'attendance' => $this->attendance($r),
            'participation' => $this->participation($r, $policy),
            'tasks' => $this->tasks($r),
            'assessments' => $this->assessments($r, $policy),
            'course' => ['value' => (float) $r->course_percent, 'applicable' => (bool) $p->has_course, 'evidence' => ['percent' => round((float) $r->course_percent)]],
            'knowledge_transfer' => $this->knowledgeTransfer($r),
            'evaluation' => ['value' => $r->evaluation()->exists() ? 100.0 : 0.0, 'applicable' => true, 'evidence' => ['done' => $r->evaluation()->exists()]],
        };
    }

    private function knowledgeTransfer(Registration $r): array
    {
        $required = (bool) ($r->program->knowledge_transfer['required'] ?? false);
        $ok = KnowledgeTransfer::where('registration_id', $r->id)->where('status', 'approved')->exists();

        return ['value' => $ok ? 100.0 : 0.0, 'applicable' => $required, 'evidence' => ['done' => $ok]];
    }

    private function attendance(Registration $r): array
    {
        $has = $r->program->sessions()->where('status', '!=', 'cancelled')->exists();

        return ['value' => (float) $r->attendance_percent, 'applicable' => $has, 'evidence' => ['percent' => round((float) $r->attendance_percent)]];
    }

    private function participation(Registration $r, array $policy): array
    {
        $rules = $policy['participation_rules'];
        $signals = [];
        if (($rules['lessons'] ?? true) && $r->program->has_course) {
            $signals['lessons'] = (float) $r->course_percent;
        }
        if ($rules['session_marks'] ?? true) {
            $sessions = $r->program->sessions()->where('status', '!=', 'cancelled')->count();
            if ($sessions > 0) {
                $signals['session_marks'] = min(100.0, Attendance::where('registration_id', $r->id)->where('participated', true)->count() / $sessions * 100);
            }
        }

        return ['value' => $signals ? round(array_sum($signals) / count($signals), 2) : 100.0, 'applicable' => $signals !== [], 'evidence' => ['signals' => array_map(fn ($v) => round($v), $signals)]];
    }

    private function tasks(Registration $r): array
    {
        $required = Task::where('program_id', $r->program_id)->where('is_required', true)->pluck('id');
        if ($required->isEmpty()) {
            return ['value' => 100.0, 'applicable' => false, 'evidence' => ['done' => 0, 'total' => 0]];
        }
        $done = TaskSubmission::where('registration_id', $r->id)->whereIn('task_id', $required)->where('status', TaskSubmission::STATUS_APPROVED)->count();

        return ['value' => round($done / $required->count() * 100, 2), 'applicable' => true, 'evidence' => ['done' => $done, 'total' => $required->count()]];
    }

    private function assessments(Registration $r, array $policy): array
    {
        $listed = collect($policy['assessment_ids'] ?? [])->filter(fn ($x) => ! empty($x['id']));
        if ($listed->isEmpty()) {
            $listed = Assessment::where('program_id', $r->program_id)->where('status', 'published')->whereIn('kind', ['final', 'quiz', 'post_test', 'comprehensive'])
                ->when($policy['test_out_assessment_id'], fn ($q, $id) => $q->where('id', '!=', $id))->pluck('id')->map(fn ($id) => ['id' => $id, 'weight' => 1]);
        }
        if ($listed->isEmpty()) {
            return ['value' => 100.0, 'applicable' => false, 'evidence' => ['items' => []]];
        }
        $best = AssessmentAttempt::where('registration_id', $r->id)->where('status', 'graded')->whereIn('assessment_id', $listed->pluck('id'))->get()->groupBy('assessment_id')->map(fn (Collection $g) => (float) $g->max('score_percent'));
        $titles = Assessment::whereIn('id', $listed->pluck('id'))->get()->keyBy('id');
        $weights = $listed->sum(fn ($x) => (float) ($x['weight'] ?? 1)) ?: 1;
        $value = $listed->sum(fn ($x) => ($best[$x['id']] ?? 0) * (float) ($x['weight'] ?? 1)) / $weights;

        return ['value' => round($value, 2), 'applicable' => true, 'evidence' => ['items' => $listed->map(fn ($x) => ['title_ar' => $titles[$x['id']]->title_ar ?? '', 'title_en' => $titles[$x['id']]->title_en ?? '', 'score' => $best[$x['id']] ?? null, 'weight' => (float) ($x['weight'] ?? 1)])->values()->all()]];
    }

    private function testOutPassed(Registration $r, array $policy): bool
    {
        if (! $policy['allow_test_out'] || ! $policy['test_out_assessment_id']) {
            return false;
        }

        return AssessmentAttempt::where('registration_id', $r->id)->where('assessment_id', $policy['test_out_assessment_id'])->where('status', 'graded')->where('passed', true)->exists();
    }

    /** Hours attended: the minutes recorded at check-out, else the session length, rounded to half an hour. */
    public function actualHours(Registration $r): float
    {
        $minutes = 0;
        Attendance::with('session')->where('registration_id', $r->id)->whereIn('status', ['present', 'late'])->get()->each(function ($a) use (&$minutes) {
            $minutes += $a->minutes_attended > 0 ? $a->minutes_attended : (int) $a->session?->durationMinutes();
        });

        return round($minutes / 60 * 2) / 2;
    }

    public function hours(Registration $r, array $policy): array
    {
        $total = (float) $r->program->total_hours;
        $actual = min($this->actualHours($r), $total ?: $this->actualHours($r));

        return ['mode' => $policy['hours_mode'], 'total' => $total, 'actual' => $actual, 'used' => $policy['hours_mode'] === 'actual' ? $actual : $total];
    }

    /** Stores the outcome on the registration and tells the trainee when it changes. */
    public function recompute(Registration $r): Registration
    {
        $result = $this->evaluate($r);
        $before = $r->pass_status;
        $ended = $r->program->end_date?->isPast() ?? false;
        $status = $result['passed'] ? ($result['via'] === 'exception' ? 'exempted' : 'passed') : ($ended ? 'failed' : 'pending');
        $participation = collect($result['criteria'])->firstWhere('key', 'participation')['value'] ?? $this->participation($r, $result['policy'])['value'];

        $r->update(['participation_percent' => $participation, 'weighted_score' => $result['weighted_score'], 'pass_status' => $status, 'passed_via' => $result['passed'] ? $result['via'] : null, 'computed_at' => now()]);

        if ($before !== $status && $r->employee?->user_id) {
            $title = $r->program;
            if (in_array($status, ['passed', 'exempted'], true)) {
                $this->notifications->send($r->employee->user_id, $result['via'] === 'test_out' ? 'testout.passed' : 'pass.criteria_met', ['ar' => 'استوفيت شروط النجاح', 'en' => 'You met the passing criteria'],
                    ['ar' => "استوفيت شروط النجاح في برنامج «{$title->title_ar}».", 'en' => "You met the passing criteria of \"{$title->title_en}\"."], ['registration_id' => $r->id, 'program_id' => $r->program_id]);
            } elseif ($status === 'failed') {
                $this->notifications->send($r->employee->user_id, 'pass.failed', ['ar' => 'لم تستوفِ شروط النجاح', 'en' => 'The passing criteria were not met'],
                    ['ar' => "لم تستوفِ شروط النجاح في برنامج «{$title->title_ar}».", 'en' => "The passing criteria of \"{$title->title_en}\" were not met."], ['registration_id' => $r->id, 'program_id' => $r->program_id]);
            }
        }

        return $r;
    }

    /** An exception needs a reason; the attachment is stored by the controller. */
    public function grantException(Registration $r, string $criterion, string $reason, ?string $path, ?string $name, User $by): PassException
    {
        $e = PassException::create(['registration_id' => $r->id, 'criterion' => $criterion, 'reason' => $reason, 'attachment_path' => $path, 'attachment_name' => $name, 'granted_by' => $by->id, 'granted_at' => now()]);
        $this->recompute($r->refresh());
        if ($r->employee?->user_id) {
            $this->notifications->send($r->employee->user_id, 'exception.granted', ['ar' => 'استثناء موثّق في سجلك', 'en' => 'A documented exception was added to your record'],
                ['ar' => "أُضيف استثناء لبرنامج «{$r->program->title_ar}».", 'en' => "An exception was added for \"{$r->program->title_en}\"."], ['registration_id' => $r->id]);
        }

        return $e;
    }

    public function revokeException(PassException $e, User $by): PassException
    {
        $e->update(['revoked_at' => now(), 'revoked_by' => $by->id]);
        $this->recompute($e->registration()->first());

        return $e;
    }
}
