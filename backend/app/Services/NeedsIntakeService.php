<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\InstitutionalRequest;
use App\Models\NeedsCycle;
use App\Models\Program;
use App\Models\ProgramProposal;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanItem;
use App\Models\User;
use App\Support\AccessScope;

/** The yearly needs cycle: opening and closing the window, department proposals, manager requests and their review. */
class NeedsIntakeService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function open(NeedsCycle $cycle): NeedsCycle
    {
        $cycle->update(['status' => NeedsCycle::OPEN, 'opens_at' => $cycle->opens_at ?? now()]);
        $ids = $this->usersWith('needs.propose', 'needs.request');
        if ($ids) {
            $this->notify($ids, 'needs.cycle_opened', ['ar' => 'فُتحت دورة الاحتياجات التدريبية', 'en' => 'The training needs cycle is open'],
                ['ar' => "«{$cycle->title_ar}» — آخر موعد للتقديم {$this->when($cycle, 'ar')}.", 'en' => "\"{$cycle->title_en}\" — submit before {$this->when($cycle, 'en')}."], ['cycle_id' => $cycle->id]);
        }

        return $cycle;
    }

    public function close(NeedsCycle $cycle): NeedsCycle
    {
        $cycle->update(['status' => NeedsCycle::CLOSED]);

        return $cycle;
    }

    /** Daily: remind once before the deadline, close what is past it. @return array{reminded: int, closed: int} */
    public function daily(): array
    {
        $reminded = 0;
        $closed = 0;
        foreach (NeedsCycle::where('status', NeedsCycle::OPEN)->get() as $cycle) {
            if ($cycle->closes_at && $cycle->closes_at->lt(now())) {
                $this->close($cycle);
                $closed++;

                continue;
            }
            $days = (int) ($cycle->settings['reminder_days'] ?? 3);
            if ($cycle->closes_at && ! $cycle->closing_reminded_at && $cycle->closes_at->lte(now()->addDays($days))) {
                $ids = $this->usersWith('needs.propose', 'needs.request');
                $reminded += $ids ? $this->notify($ids, 'needs.cycle_closing', ['ar' => 'تغلق دورة الاحتياجات قريباً', 'en' => 'The needs cycle closes soon'],
                    ['ar' => "«{$cycle->title_ar}» تغلق في {$this->when($cycle, 'ar')}.", 'en' => "\"{$cycle->title_en}\" closes on {$this->when($cycle, 'en')}."], ['cycle_id' => $cycle->id]) : 0;
                $cycle->update(['closing_reminded_at' => now()]);
            }
        }

        return ['reminded' => $reminded, 'closed' => $closed];
    }

    public function currentCycle(): ?NeedsCycle
    {
        return NeedsCycle::where('status', NeedsCycle::OPEN)->orderByDesc('year')->get()->first(fn (NeedsCycle $c) => $c->isOpen());
    }

    public function assertOpen(NeedsCycle $cycle): void
    {
        if (! $cycle->isOpen()) {
            throw new BusinessRuleException(__('messages.needs.cycle_closed'), 'cycle_closed');
        }
    }

    public function submitProposal(NeedsCycle $cycle, array $data, User $by): ProgramProposal
    {
        $this->assertOpen($cycle);
        $school = $by->employee?->school;

        return ProgramProposal::create($data + [
            'cycle_id' => $cycle->id, 'submitted_by' => $by->id, 'entity_type' => $school ? 'school' : 'department',
            'entity_id' => $school?->id, 'entity_name' => $school?->translate('name') ?? $by->displayName(), 'status' => 'submitted',
        ]);
    }

    public function updateProposal(ProgramProposal $proposal, array $data, User $by): ProgramProposal
    {
        $this->assertOpen($proposal->cycle);
        if ($proposal->submitted_by !== $by->id) {
            throw new BusinessRuleException(__('messages.needs.not_owner'), 'not_owner');
        }
        $proposal->update($data);

        return $proposal;
    }

    public function reviewProposal(ProgramProposal $proposal, string $decision, ?string $note, ?string $programId, ?int $rank, User $by): ProgramProposal
    {
        $this->assertReview($decision, $note, $programId);
        $itemId = null;
        if ($decision === 'accepted') {
            $itemId = $this->toPlanItem($proposal->cycle, [
                'title_ar' => $proposal->program_title_ar, 'title_en' => $proposal->program_title_en ?: $proposal->program_title_ar,
                'audience' => $proposal->target_job_title_ids ? ['job_title_ids' => $proposal->target_job_title_ids] : null, 'priority' => $this->priority($proposal->importance),
                'priority_score' => $proposal->importance * 20, 'planned_groups' => max(1, $proposal->groups_count), 'planned_seats' => 0,
                'planned_hours' => $proposal->groups_count * (float) $proposal->hours, 'source' => 'needs', 'source_refs' => [$proposal->id],
                'rationale_ar' => $proposal->justification, 'rationale_en' => $proposal->justification, 'program_id' => $programId,
            ]);
        }
        $proposal->update(['status' => $decision, 'review_note' => $note, 'reviewer_id' => $by->id, 'existing_program_id' => $programId ?? $proposal->existing_program_id, 'priority_rank' => $rank ?? $proposal->priority_rank, 'plan_item_id' => $itemId ?? $proposal->plan_item_id]);
        $this->tell($proposal->submitted_by, 'proposal.reviewed', $decision, $proposal->program_title_ar, $proposal->program_title_en ?: $proposal->program_title_ar, $note);

        return $proposal;
    }

    public function submitRequest(array $data, User $by): InstitutionalRequest
    {
        $cycle = $this->currentCycle();
        if (! $cycle) {
            throw new BusinessRuleException(__('messages.needs.cycle_closed'), 'cycle_closed');
        }
        $manager = $by->employee;
        $ids = array_values(array_unique($data['employee_ids'] ?? []));
        if ($ids) {
            $allowed = Employee::whereIn('id', $ids)->get()->filter(fn (Employee $e) => $this->manages($by, $e))->pluck('id')->all();
            if (count($allowed) !== count($ids)) {
                throw new BusinessRuleException(__('messages.needs.not_your_staff'), 'not_your_staff');
            }
        }

        return InstitutionalRequest::create(array_merge($data, ['employee_ids' => $ids, 'cycle_id' => $cycle->id, 'requested_by' => $by->id, 'entity_id' => $manager?->school_id, 'entity_name' => $manager?->school?->translate('name'), 'status' => 'submitted']));
    }

    public function reviewRequest(InstitutionalRequest $request, string $decision, ?string $note, ?string $programId, User $by): InstitutionalRequest
    {
        $this->assertReview($decision, $note, $programId);
        $itemId = null;
        if ($decision === 'accepted' && $request->cycle) {
            $program = $request->program_id ? Program::find($request->program_id) : null;
            $itemId = $this->toPlanItem($request->cycle, [
                'title_ar' => $program?->title_ar ?? $request->title, 'title_en' => $program?->title_en ?? $request->title, 'priority' => $this->priority($request->need_degree), 'priority_score' => $request->need_degree * 20,
                'planned_groups' => 1, 'planned_seats' => count($request->employee_ids ?? []), 'planned_hours' => (float) ($program?->total_hours ?? 0), 'source' => 'needs', 'source_refs' => [$request->id],
                'rationale_ar' => implode('، ', $request->objectives ?? []), 'rationale_en' => implode(', ', $request->objectives ?? []), 'program_id' => $program?->id,
            ]);
        }
        $request->update(['status' => $decision, 'review_note' => $note, 'reviewer_id' => $by->id, 'program_id' => $programId ?? $request->program_id, 'plan_item_id' => $itemId ?? $request->plan_item_id]);
        $this->tell($request->requested_by, 'request.reviewed', $decision, (string) $request->title, (string) $request->title, $note);

        return $request;
    }

    /** Is $employee someone $user may speak for: their direct report, or inside their scope when they hold a school-level role. */
    public function manages(User $user, Employee $employee): bool
    {
        if ($user->employee && $employee->supervisor_id === $user->employee->id) {
            return true;
        }
        if ($user->hasPermission('needs.cycles')) {
            return true;
        }

        return $user->employee !== null && AccessScope::current($user)->singleSchoolId() !== null && AccessScope::current($user)->allowsEmployee($employee);
    }

    private function assertReview(string $decision, ?string $note, ?string $programId): void
    {
        if ($decision === 'rejected' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        if ($decision === 'merged' && ! $programId) {
            throw new BusinessRuleException(__('messages.needs.merge_target'), 'merge_target_required');
        }
    }

    /** Adds the accepted item to the cycle's plan draft (when the cycle has a plan that is still editable). */
    private function toPlanItem(?NeedsCycle $cycle, array $item): ?string
    {
        $plan = $cycle?->plan_id ? TrainingPlan::find($cycle->plan_id) : null;
        if (! $plan || in_array($plan->status, [TrainingPlan::CLOSED, TrainingPlan::IN_REVIEW], true)) {
            return null;
        }

        return TrainingPlanItem::create($item + ['plan_id' => $plan->id])->id;
    }

    private function priority(int $degree): string
    {
        return $degree >= 5 ? 'critical' : ($degree === 4 ? 'high' : ($degree === 3 ? 'medium' : 'low'));
    }

    private function when(NeedsCycle $cycle, string $lang): string
    {
        return $cycle->closes_at ? $cycle->closes_at->copy()->locale($lang)->translatedFormat('j F Y') : '—';
    }

    private function tell(?string $userId, string $event, string $decision, string $titleAr, string $titleEn, ?string $note): void
    {
        if (! $userId) {
            return;
        }
        $label = ['ar' => ['accepted' => 'قُبل', 'merged' => 'دُمج في برنامج قائم', 'rejected' => 'رُفض', 'under_review' => 'قيد المراجعة'], 'en' => ['accepted' => 'accepted', 'merged' => 'merged into an existing program', 'rejected' => 'rejected', 'under_review' => 'under review']];
        $this->notifications->send($userId, $event, ['ar' => 'تمت مراجعة طلبك', 'en' => 'Your submission was reviewed'],
            ['ar' => "«{$titleAr}»: {$label['ar'][$decision]}".($note ? " — {$note}" : '').'.', 'en' => "\"{$titleEn}\": {$label['en'][$decision]}".($note ? " — {$note}" : '').'.'],
            ['detail_ar' => "«{$titleAr}»: {$label['ar'][$decision]}", 'detail_en' => "\"{$titleEn}\": {$label['en'][$decision]}"]);
    }

    /** @param  list<string>  $ids */
    private function notify(array $ids, string $event, array $title, array $body, array $data): int
    {
        return $this->notifications->broadcast($ids, $event, $title, $body, $data + ['detail_ar' => $body['ar'], 'detail_en' => $body['en']]);
    }

    /** @return list<string> */
    private function usersWith(string ...$permissions): array
    {
        return User::whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', $permissions))->pluck('id')->all();
    }
}
