<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Models\TrainingNeed;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanChange;
use App\Models\TrainingPlanItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The annual training plan: generated from approved needs by editable yearly rules, reviewed, approved
 * (baseline snapshot), executed and monitored (execution %, change %, deviations, emergency share).
 */
class AnnualPlanService
{
    public const DEFAULT_RULES = [
        'program_types' => ['licensing', 'empowerment', 'apprenticeship', 'specialisation', 'electives'],
        'mandatory_categories' => [],
        'weights' => ['severity' => 60, 'headcount' => 30, 'breadth' => 10],
        'max_seats_per_group' => 25,
        'default_hours_per_group' => 12,
        'max_seats_total' => null,
        'max_hours_total' => null,
        'min_fill_percent' => 50,
        'windows' => ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4],
        'carry_over' => true,
    ];

    /** Needs in these statuses feed the plan. */
    private const NEED_STATUSES = ['submitted', 'under_review', 'approved'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function rules(TrainingPlan $plan): array
    {
        return array_replace_recursive(self::DEFAULT_RULES, $plan->rules ?? []);
    }

    public function create(array $data, User $by): TrainingPlan
    {
        $version = (int) TrainingPlan::where('year', $data['year'])->max('version') + 1;
        if ($version > 1 && ! ($data['new_version'] ?? false)) {
            throw new BusinessRuleException(__('messages.plans.exists', ['year' => $data['year']]), 'plan_exists');
        }

        return TrainingPlan::create(['year' => $data['year'], 'version' => $version, 'title_ar' => $data['title_ar'], 'title_en' => $data['title_en'], 'status' => TrainingPlan::DRAFT, 'rules' => self::DEFAULT_RULES, 'notes' => $data['notes'] ?? null]);
    }

    /** Draft items from needs (grouped by topic) and from unfinished items of the previous year; existing topics are skipped. */
    public function generate(TrainingPlan $plan): array
    {
        $this->assertEditable($plan);
        $rules = $this->rules($plan);
        $existing = $plan->items()->get();
        $taken = $existing->pluck('source_refs')->flatten()->filter()->flip();
        $titles = $existing->map(fn ($i) => mb_strtolower($i->title_en))->flip();

        $needs = TrainingNeed::whereIn('status', self::NEED_STATUSES)->whereNotIn('id', $taken->keys())->get();
        $buckets = $needs->groupBy(fn ($n) => $n->skill_id ?: mb_strtolower(trim($n->skill_name)));
        $maxSeats = max(1, (int) $buckets->map(fn (Collection $b) => $b->sum('employees_count'))->max());
        $maxSchools = max(1, (int) $buckets->map(fn (Collection $b) => $b->pluck('school_id')->unique()->count())->max());
        $w = $rules['weights'];
        $added = 0;

        DB::transaction(function () use ($plan, $buckets, $maxSeats, $maxSchools, $w, $rules, $titles, &$added) {
            foreach ($buckets as $bucket) {
                $first = $bucket->first();
                $name = trim($first->skill_name);
                if (isset($titles[mb_strtolower($name)])) {
                    continue;
                }
                $seats = (int) $bucket->sum('employees_count');
                $schools = $bucket->pluck('school_id')->unique()->count();
                $top = $bucket->sortByDesc(fn ($n) => TrainingNeed::PRIORITY_WEIGHT[$n->priority] ?? 1)->first()->priority;
                $score = round(($w['severity'] * (TrainingNeed::PRIORITY_WEIGHT[$top] ?? 1) / 4) + ($w['headcount'] * $seats / $maxSeats) + ($w['breadth'] * $schools / $maxSchools), 2);
                $groups = max(1, (int) ceil($seats / max(1, $rules['max_seats_per_group'])));
                $quarter = $rules['windows'][$top] ?? 2;
                $start = now()->setDate($plan->year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

                TrainingPlanItem::create([
                    'plan_id' => $plan->id, 'title_ar' => $name, 'title_en' => $name, 'category_id' => null,
                    'priority' => $top, 'priority_score' => $score, 'planned_groups' => $groups, 'planned_seats' => $seats,
                    'planned_hours' => $groups * $rules['default_hours_per_group'], 'window_start' => $start->toDateString(), 'window_end' => $start->copy()->addMonths(3)->subDay()->toDateString(),
                    'source' => 'needs', 'source_refs' => $bucket->pluck('id')->all(),
                    'rationale_ar' => "{$seats} متدرباً من {$schools} مدرسة، أعلى أولوية: ".(['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'critical' => 'حرجة'][$top] ?? $top).". الدرجة {$score}.",
                    'rationale_en' => "{$seats} trainees from {$schools} school(s), top priority: {$top}. Score {$score}.",
                ]);
                $added++;
            }
        });

        $carried = 0;
        if ($rules['carry_over']) {
            $previous = TrainingPlan::where('year', $plan->year - 1)->orderByDesc('version')->first();
            foreach ($previous?->items()->whereIn('status', ['planned', 'postponed', 'in_execution'])->get() ?? [] as $old) {
                if (isset($titles[mb_strtolower($old->title_en)]) || $plan->items()->where('source_refs', 'like', '%'.$old->id.'%')->exists()) {
                    continue;
                }
                $plan->items()->create($old->only(['title_ar', 'title_en', 'category_id', 'audience', 'priority', 'priority_score', 'planned_groups', 'planned_seats', 'planned_hours', 'program_id']) + [
                    'source' => 'carry_over', 'source_refs' => [$old->id], 'rationale_ar' => 'مرحّل من خطة '.$previous->year.' لعدم اكتماله.', 'rationale_en' => 'Carried over from the '.$previous->year.' plan: not completed.',
                ]);
                $carried++;
            }
        }

        $this->audit($plan, 'generated', ['added' => $added, 'carried_over' => $carried]);

        return ['added' => $added, 'carried_over' => $carried];
    }

    public function addItem(TrainingPlan $plan, array $data, User $by): TrainingPlanItem
    {
        $this->assertEditable($plan);
        $emergency = (bool) ($data['is_emergency'] ?? false);
        if ($plan->isBaselined() && ! filled($data['reason'] ?? null)) {
            throw new BusinessRuleException(__('messages.plans.reason_required'), 'reason_required');
        }
        $item = $plan->items()->create(array_diff_key($data, ['reason' => 1]) + ['source' => $emergency ? 'emergency' : 'manual']);
        if ($plan->isBaselined()) {
            $this->log($plan, $item->id, 'added', null, $item->toArray(), $data['reason'], $by);
        }

        return $item;
    }

    public function updateItem(TrainingPlan $plan, TrainingPlanItem $item, array $data, User $by): TrainingPlanItem
    {
        $this->assertEditable($plan);
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        if ($plan->isBaselined() && ! filled($reason)) {
            throw new BusinessRuleException(__('messages.plans.reason_required'), 'reason_required');
        }
        $before = $item->only(array_keys($data));
        $item->update($data);
        if ($plan->isBaselined()) {
            $type = in_array($data['status'] ?? null, ['postponed', 'cancelled'], true) ? $data['status'] : 'modified';
            $this->log($plan, $item->id, $type, $before, $item->only(array_keys($data)), $reason, $by);
        }

        return $item->fresh();
    }

    public function removeItem(TrainingPlan $plan, TrainingPlanItem $item, ?string $reason, User $by): void
    {
        $this->assertEditable($plan);
        if ($plan->isBaselined()) {
            if (! filled($reason)) {
                throw new BusinessRuleException(__('messages.plans.reason_required'), 'reason_required');
            }
            $this->log($plan, $item->id, 'removed', $item->toArray(), null, $reason, $by);
        }
        $item->delete();
    }

    public function submit(TrainingPlan $plan, User $by): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::DRAFT]);
        if (! $plan->items()->exists()) {
            throw new BusinessRuleException(__('messages.plans.empty'), 'plan_empty');
        }
        $plan->update(['status' => TrainingPlan::IN_REVIEW, 'submitted_by' => $by->id, 'submitted_at' => now()]);
        $this->tell($this->usersWith('plans.approve', 'plans.manage'), 'plan.submitted', $plan, ['ar' => 'خطة تدريبية بانتظار المراجعة', 'en' => 'A training plan awaits review'], $by->id);

        return $plan;
    }

    public function return(TrainingPlan $plan, ?string $comment, User $by): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::IN_REVIEW]);
        $plan->update(['status' => TrainingPlan::DRAFT, 'notes' => $comment ?: $plan->notes]);
        $this->tell(array_filter([$plan->submitted_by]), 'plan.returned', $plan, ['ar' => 'أُعيدت الخطة التدريبية للتعديل', 'en' => 'The training plan was returned for changes'], $by->id, $comment);

        return $plan;
    }

    public function approve(TrainingPlan $plan, User $by, ?string $signedPath = null): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::IN_REVIEW]);
        $items = $plan->items()->get();
        $plan->update([
            'status' => TrainingPlan::APPROVED, 'approved_by' => $by->id, 'approved_at' => now(), 'signed_pdf_path' => $signedPath ?? $plan->signed_pdf_path,
            'baseline' => ['approved_at' => now()->toIso8601String(), 'items' => $items->map(fn ($i) => $i->only(['id', 'title_ar', 'title_en', 'priority', 'planned_groups', 'planned_seats', 'planned_hours', 'window_start', 'window_end', 'status']))->all()],
        ]);
        $this->tell(array_filter([$plan->submitted_by]), 'plan.approved', $plan, ['ar' => 'اعتُمدت الخطة التدريبية', 'en' => 'The training plan was approved'], $by->id);

        return $plan;
    }

    public function activate(TrainingPlan $plan): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::APPROVED]);
        $plan->update(['status' => TrainingPlan::ACTIVE]);

        return $plan;
    }

    public function close(TrainingPlan $plan): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::ACTIVE, TrainingPlan::APPROVED]);
        $plan->update(['status' => TrainingPlan::CLOSED]);

        return $plan;
    }

    /** Planned vs created vs executed, change and delay figures, and the deviation list. */
    public function execution(TrainingPlan $plan): array
    {
        $rules = $this->rules($plan);
        $items = $plan->items()->with(['groups' => fn ($q) => $q->withCount(['registrations as taken' => fn ($r) => $r->whereIn('status', Registration::SEAT_HOLDING)])])->get();
        $today = today();
        $deviations = [];
        $rows = [];

        foreach ($items as $item) {
            $live = $item->groups->whereNotIn('status', [TrainingGroup::CANCELLED]);
            $executed = $item->groups->where('status', TrainingGroup::COMPLETED);
            $seats = (int) $live->sum('taken');
            $row = [
                'id' => $item->id, 'title_ar' => $item->title_ar, 'title_en' => $item->title_en, 'status' => $item->status, 'priority' => $item->priority,
                'planned_groups' => $item->planned_groups, 'created_groups' => $live->count(), 'executed_groups' => $executed->count(),
                'planned_seats' => $item->planned_seats, 'enrolled_seats' => $seats, 'planned_hours' => $item->planned_hours,
                'percent' => $item->planned_groups ? min(100, round($executed->count() / $item->planned_groups * 100, 1)) : 0,
                'window_start' => $item->window_start?->toDateString(), 'window_end' => $item->window_end?->toDateString(),
            ];
            $rows[] = $row;

            if (! in_array($item->status, ['done', 'cancelled', 'postponed'], true)) {
                if ($item->window_end && $item->window_end->lt($today) && $live->count() < $item->planned_groups) {
                    $deviations[] = $this->deviation('late', $item, null, ['ar' => 'انتهت نافذة التنفيذ دون إنشاء المجموعات المخططة', 'en' => 'The window passed without the planned groups']);
                }
            }
            foreach ($item->groups as $g) {
                if ($g->status === TrainingGroup::CANCELLED) {
                    $deviations[] = $this->deviation('cancelled', $item, $g, ['ar' => 'مجموعة ملغاة', 'en' => 'Cancelled group']);
                } elseif ($g->status === TrainingGroup::POSTPONED) {
                    $deviations[] = $this->deviation('postponed', $item, $g, ['ar' => 'مجموعة مؤجلة', 'en' => 'Postponed group']);
                } elseif (in_array($g->status, [TrainingGroup::ONGOING, TrainingGroup::COMPLETED], true) && $g->capacity > 0 && $g->taken * 100 / $g->capacity < $rules['min_fill_percent']) {
                    $deviations[] = $this->deviation('under_filled', $item, $g, ['ar' => "أقل من {$rules['min_fill_percent']}% من المقاعد", 'en' => "Below {$rules['min_fill_percent']}% of seats filled"]);
                }
            }
        }

        $emergency = TrainingGroup::where('is_emergency', true)->whereYear('start_date', $plan->year)->get();
        foreach ($emergency->whereNull('plan_item_id') as $g) {
            $deviations[] = ['type' => 'unplanned', 'item_id' => null, 'group_id' => $g->id, 'title_ar' => $g->displayTitle('ar'), 'title_en' => $g->displayTitle('en'), 'message' => ['ar' => 'مجموعة طارئة خارج الخطة', 'en' => 'Emergency group outside the plan']];
        }

        $plannedGroups = (int) $items->sum('planned_groups');
        $executedGroups = (int) collect($rows)->sum('executed_groups');
        $changed = $plan->changes()->whereNotNull('item_id')->distinct()->count('item_id');
        $baseline = count($plan->baseline['items'] ?? []);
        $allGroups = $executedGroups + $emergency->count();

        return [
            'totals' => [
                'items' => $items->count(), 'planned_groups' => $plannedGroups, 'created_groups' => (int) collect($rows)->sum('created_groups'), 'executed_groups' => $executedGroups,
                'planned_seats' => (int) $items->sum('planned_seats'), 'enrolled_seats' => (int) collect($rows)->sum('enrolled_seats'), 'planned_hours' => (float) $items->sum('planned_hours'),
                'execution_percent' => $plannedGroups ? round($executedGroups / $plannedGroups * 100, 1) : 0,
                'changed_percent' => $baseline ? round($changed / $baseline * 100, 1) : 0,
                'emergency_groups' => $emergency->count(), 'emergency_percent' => $allGroups ? round($emergency->count() / $allGroups * 100, 1) : 0,
                'deviations' => count($deviations),
            ],
            'items' => $rows,
            'deviations' => $deviations,
        ];
    }

    /** Daily job: tell planning and training heads about deviations of active plans. */
    public function notifyDeviations(): int
    {
        $sent = 0;
        foreach (TrainingPlan::where('status', TrainingPlan::ACTIVE)->get() as $plan) {
            $count = count($this->execution($plan)['deviations']);
            if ($count) {
                $sent += $this->tell($this->usersWith('plans.manage'), 'plan.deviation_detected', $plan, ['ar' => "رُصد {$count} انحراف في الخطة التدريبية", 'en' => "{$count} deviation(s) detected in the training plan"], null);
            }
        }

        return $sent;
    }

    private function deviation(string $type, TrainingPlanItem $item, ?TrainingGroup $group, array $message): array
    {
        return ['type' => $type, 'item_id' => $item->id, 'group_id' => $group?->id, 'title_ar' => $item->title_ar, 'title_en' => $item->title_en, 'message' => $message];
    }

    private function assertEditable(TrainingPlan $plan): void
    {
        if ($plan->status === TrainingPlan::CLOSED || $plan->status === TrainingPlan::IN_REVIEW) {
            throw new BusinessRuleException(__('messages.plans.locked'), 'plan_locked');
        }
    }

    private function expect(TrainingPlan $plan, array $statuses): void
    {
        if (! in_array($plan->status, $statuses, true)) {
            throw new BusinessRuleException(__('messages.plans.invalid_state', ['status' => $plan->status]), 'plan_invalid_state');
        }
    }

    private function log(TrainingPlan $plan, ?string $itemId, string $type, ?array $before, ?array $after, ?string $reason, User $by): void
    {
        TrainingPlanChange::create(['plan_id' => $plan->id, 'item_id' => $itemId, 'change_type' => $type, 'before' => $before, 'after' => $after, 'reason' => $reason, 'changed_by' => $by->id]);
    }

    private function audit(TrainingPlan $plan, string $action, array $data): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => 'plan_'.$action, 'auditable_type' => TrainingPlan::class, 'auditable_id' => $plan->id, 'new_values' => $data]);
    }

    /** @return list<string> */
    private function usersWith(string ...$permissions): array
    {
        return User::whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', $permissions))->pluck('id')->all();
    }

    private function tell(array $userIds, string $event, TrainingPlan $plan, array $title, ?string $exceptId, ?string $comment = null): int
    {
        $ids = array_values(array_diff($userIds, array_filter([$exceptId])));
        if (! $ids) {
            return 0;
        }

        return $this->notifications->broadcast($ids, $event, $title, ['ar' => "«{$plan->title_ar}» ({$plan->year})".($comment ? " — {$comment}" : ''), 'en' => "\"{$plan->title_en}\" ({$plan->year})".($comment ? " — {$comment}" : '')], ['plan_id' => $plan->id, 'route' => '/admin/plans']);
    }
}
