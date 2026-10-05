<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IndividualNeed;
use App\Models\JobCompetencyRequirement;
use App\Models\NeedsSurvey;
use App\Models\NeedsSurveyResponse;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;

/** Individual development needs: declared by the employee, generated from surveys and rules, approved by the direct manager. */
class IndividualNeedsService
{
    public function __construct(private readonly NotificationService $notifications, private readonly NeedsCycleLookup $cycles) {}

    public function autoApproveDays(): int
    {
        return (int) (SiteSetting::find('needs.auto_approve_days')?->value['days'] ?? 14);
    }

    /** Creates or refreshes a need. Decided needs are never reopened. */
    public function upsert(Employee $employee, string $skillId, string $source, array $attrs = []): ?IndividualNeed
    {
        $need = IndividualNeed::firstOrNew(['employee_id' => $employee->id, 'skill_id' => $skillId, 'source' => $source]);
        if ($need->exists && $need->status !== 'pending_manager') {
            return null;
        }
        $isNew = ! $need->exists;
        $current = $attrs['current_level'] ?? null;
        $required = $attrs['required_level'] ?? null;
        $gap = $attrs['gap'] ?? ($required !== null ? CompetencyService::gap($current === null ? null : (float) $current, (int) $required) : 0);
        $need->fill($attrs + ['cycle_id' => $this->cycles->currentId()])->fill(['gap' => $gap, 'status' => $attrs['status'] ?? 'pending_manager'])->save();

        return $isNew ? $need : null;
    }

    public function declare(Employee $employee, string $skillId, ?string $why): IndividualNeed
    {
        $need = $this->upsert($employee, $skillId, 'self', ['explanation_ar' => $why, 'explanation_en' => $why]) ?? IndividualNeed::where(['employee_id' => $employee->id, 'skill_id' => $skillId, 'source' => 'self'])->firstOrFail();
        $this->notifyManagers([$need]);

        return $need;
    }

    /** Needs a survey answer reveals: self-rated competence below what the job requires, or an explicit need. */
    public function fromSurveyResponse(NeedsSurvey $survey, NeedsSurveyResponse $response): int
    {
        $employee = $response->user?->employee;
        if (! $employee) {
            return 0;
        }
        $created = [];
        $rows = [];
        foreach ($survey->questions ?? [] as $q) {
            $scale = $q['scale'] ?? ['min' => 1, 'max' => 5];
            $mode = $q['mode'] ?? 'competence';
            $answer = $response->answers[$q['id']] ?? null;
            $items = ($q['type'] ?? '') === 'matrix' && is_array($answer) ? collect($q['rows'] ?? [])->mapWithKeys(fn ($r) => [$r['id'] => [$r, $answer[$r['id']] ?? null]]) : collect([$q['id'] => [$q, $answer]]);
            foreach ($items as [$src, $value]) {
                if (empty($src['skill_id']) || ! is_numeric($value)) {
                    continue;
                }
                $level = (int) round(((float) $value - $scale['min']) / max(1, $scale['max'] - $scale['min']) * 4 + 1);
                $rows[] = [$src['skill_id'], $mode === 'need' ? null : $level, $mode === 'need' ? $level : null];
            }
        }
        $req = JobCompetencyRequirement::where('job_title_id', $employee->job_title_id)->get();
        foreach ($rows as [$skillId, $current, $needStrength]) {
            $r = app(CompetencyService::class)->requirementFor($employee, $skillId, $req);
            $required = $r?->required_level ?? 3;
            $gap = $current !== null ? max(0, $required - $current) : ($needStrength >= 4 ? 2 : ($needStrength === 3 ? 1 : 0));
            if ($gap < 1) {
                continue;
            }
            $need = $this->upsert($employee, $skillId, 'self_survey', ['source_ref' => ['survey_id' => $survey->id], 'current_level' => $current, 'required_level' => $required, 'gap' => $gap, 'priority_score' => $gap * 10,
                'explanation_ar' => 'من إجاباتك في استبانة «'.$survey->title.'».', 'explanation_en' => 'From your answers in the survey "'.$survey->title.'".']);
            $need && $created[] = $need;
        }
        $this->notifyManagers($created);

        return count($created);
    }

    public function query(User $user): Builder
    {
        $q = IndividualNeed::query()->with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en', 'skill:id,name_ar,name_en,code']);
        if ($user->hasPermission('needs.cycles')) {
            return $q->whereHas('employee', fn ($e) => AccessScope::current($user)->constrainEmployees($e));
        }
        $mine = $user->employee?->id;

        return $q->whereHas('employee', fn ($e) => $e->where('supervisor_id', $mine ?? '00000000-0000-0000-0000-000000000000'));
    }

    /**
     * Bulk decision by a manager.
     *
     * @param  list<string>  $ids
     * @return array{decided: int, skipped: int}
     */
    public function decide(User $by, array $ids, string $decision, ?string $note): array
    {
        if ($decision === 'rejected' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        $needs = $this->query($by)->whereIn('id', $ids)->where('status', 'pending_manager')->get();
        foreach ($needs as $need) {
            $need->update(['status' => $decision, 'manager_id' => $by->id, 'manager_note' => $note, 'decided_at' => now()]);
            $this->tellEmployee($need, $decision, $note);
        }

        return ['decided' => $needs->count(), 'skipped' => count($ids) - $needs->count()];
    }

    /** Daily: needs a manager did not act on within N days are approved (and audited). */
    public function autoApprove(): int
    {
        $days = $this->autoApproveDays();
        if ($days <= 0) {
            return 0;
        }
        $needs = IndividualNeed::where('status', 'pending_manager')->where('created_at', '<=', now()->subDays($days))->get();
        foreach ($needs as $need) {
            $need->update(['status' => 'auto_approved', 'decided_at' => now(), 'manager_note' => "Approved automatically after {$days} days without a decision"]);
            AuditLog::create(['action' => 'individual_need_auto_approved', 'auditable_type' => IndividualNeed::class, 'auditable_id' => $need->id, 'new_values' => ['days' => $days]]);
            $this->tellEmployee($need, 'auto_approved', null);
        }

        return $needs->count();
    }

    /** One digest per manager for the needs just created. @param  list<IndividualNeed>  $needs */
    public function notifyManagers(array $needs): void
    {
        $byManager = collect($needs)->groupBy(fn (IndividualNeed $n) => $n->employee?->supervisor?->user_id)->filter(fn ($g, $k) => $k);
        foreach ($byManager as $managerId => $group) {
            $n = $group->count();
            $this->notifications->send($managerId, 'individual_need.awaiting_manager', ['ar' => 'احتياجات تدريبية بانتظار اعتمادك', 'en' => 'Training needs await your approval'],
                ['ar' => "{$n} احتياج بانتظار قرارك.", 'en' => "{$n} need(s) await your decision."], ['detail_ar' => "{$n} احتياج بانتظار قرارك.", 'detail_en' => "{$n} need(s) await your decision.", 'route' => '/needs']);
        }
    }

    private function tellEmployee(IndividualNeed $need, string $decision, ?string $note): void
    {
        $uid = $need->employee?->user_id;
        if (! $uid) {
            return;
        }
        $label = ['approved' => ['اعتُمد', 'approved'], 'auto_approved' => ['اعتُمد تلقائياً', 'approved automatically'], 'rejected' => ['لم يُعتمد', 'not approved']][$decision];
        $skill = $need->skill;
        $this->notifications->send($uid, 'individual_need.decided', ['ar' => 'قرار بشأن احتياجك التدريبي', 'en' => 'Decision on your training need'],
            ['ar' => "احتياجك في «{$skill?->name_ar}» {$label[0]}".($note ? " — {$note}" : '').'.', 'en' => "Your need for \"{$skill?->name_en}\" {$label[1]}".($note ? " — {$note}" : '').'.'],
            ['detail_ar' => "احتياجك في «{$skill?->name_ar}» {$label[0]}", 'detail_en' => "Your need for \"{$skill?->name_en}\" {$label[1]}", 'route' => '/needs']);
    }
}
