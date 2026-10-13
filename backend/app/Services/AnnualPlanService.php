<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Models\TrainingNeed;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanChange;
use App\Models\TrainingPlanItem;
use App\Models\User;
use App\Support\ProgramTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The annual training plan: generated from approved needs by editable yearly rules, reviewed, approved
 * (baseline snapshot), executed and monitored (execution %, change %, deviations, emergency share).
 * The year's rules (NDS-08) are enforced: only the year's programme types, every mandatory category, and the
 * seat and hour caps; a broken rule blocks submission unless the head of planning documents an exception.
 */
class AnnualPlanService
{
    public const DEFAULT_RULES = [
        'program_types' => ProgramTypes::ALL,
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

    /** Rules holding a list: a saved list replaces the default instead of being merged position by position. */
    private const LIST_RULES = ['program_types', 'mandatory_categories'];

    /** Items in these statuses are not part of the year's totals or its execution. */
    public const OUT_OF_TOTALS = ['deferred', 'cancelled'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function rules(TrainingPlan $plan): array
    {
        return $this->mergeRules(self::DEFAULT_RULES, $plan->rules ?? []);
    }

    /** Applies rule changes: maps (weights, windows) merge key by key, lists and single values replace. An empty list means "no rule". */
    public function mergeRules(array $base, array $changes): array
    {
        $out = array_replace_recursive($base, array_diff_key($changes, array_flip(self::LIST_RULES)));
        foreach (self::LIST_RULES as $key) {
            if (array_key_exists($key, $changes)) {
                $out[$key] = array_values((array) $changes[$key]);
            }
        }
        $out['program_types'] = ProgramTypes::normalizeList($out['program_types'] ?? []);

        return $out;
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

        $buckets = $this->needBuckets($taken->keys()->all());
        $excluded = [];
        foreach ($buckets as $key => $bucket) {
            if (! $this->typeAllowed($this->bucketType($bucket), $rules)) {
                $excluded[] = $this->excludedRow($bucket);
                $buckets->forget($key);
            }
        }
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
                $program = $this->bucketProgram($bucket);

                TrainingPlanItem::create([
                    'plan_id' => $plan->id, 'title_ar' => $name, 'title_en' => $name, 'category_id' => $program?->category_id, 'program_type' => $program?->effectiveType(),
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
                $plan->items()->create($old->only(['title_ar', 'title_en', 'category_id', 'program_type', 'audience', 'priority', 'priority_score', 'planned_groups', 'planned_seats', 'planned_hours', 'program_id']) + [
                    'source' => 'carry_over', 'source_refs' => [$old->id], 'rationale_ar' => 'مرحّل من خطة '.$previous->year.' لعدم اكتماله.', 'rationale_en' => 'Carried over from the '.$previous->year.' plan: not completed.',
                ]);
                $carried++;
            }
        }

        $placeholders = $this->addMandatoryPlaceholders($plan, $rules);
        $deferred = $this->applyCaps($plan, $rules);
        $this->audit($plan, 'generated', ['added' => $added, 'carried_over' => $carried, 'mandatory_placeholders' => $placeholders, 'deferred' => $deferred, 'excluded' => count($excluded)]);

        return ['added' => $added, 'carried_over' => $carried, 'mandatory_placeholders' => $placeholders, 'deferred' => $deferred, 'excluded' => $excluded];
    }

    /**
     * How the plan stands against the year's rules: seats and hours against the caps, items per type and category,
     * mandatory categories covered, needs left out because of their type, the violations that block submission and
     * the warnings (items without a type).
     */
    public function compliance(TrainingPlan $plan): array
    {
        $rules = $this->rules($plan);
        $items = $plan->items()->get();
        $active = $items->whereNotIn('status', self::OUT_OF_TOTALS);
        $seats = (int) $active->sum('planned_seats');
        $hours = (float) $active->sum('planned_hours');
        $categories = ProgramCategory::whereIn('id', array_values(array_unique(array_merge($rules['mandatory_categories'], $active->pluck('category_id')->filter()->all()))))->get()->keyBy('id');
        $violations = [];

        $notAllowed = $active->reject(fn (TrainingPlanItem $i) => $this->typeAllowed($i->program_type, $rules));
        if ($notAllowed->isNotEmpty()) {
            $names = $notAllowed->pluck('program_type')->unique()->map(fn ($t) => ProgramTypes::name($t))->implode('، ');
            $violations[] = $this->violation('type_not_allowed', $notAllowed->pluck('id')->all(), "أنواع برامج خارج خطة هذا العام: {$names}", 'Programme types outside this year\'s plan: '.$notAllowed->pluck('program_type')->unique()->map(fn ($t) => ProgramTypes::name($t, 'en'))->implode(', '));
        }
        $mandatory = collect($rules['mandatory_categories'])->map(fn ($id) => ['id' => $id, 'name_ar' => $categories[$id]->name_ar ?? '—', 'name_en' => $categories[$id]->name_en ?? '—', 'covered' => $active->contains('category_id', $id)])->values();
        $missing = $mandatory->where('covered', false);
        if ($missing->isNotEmpty()) {
            $violations[] = $this->violation('mandatory_missing', [], 'فئات إلزامية بلا بنود: '.$missing->pluck('name_ar')->implode('، '), 'Mandatory categories without items: '.$missing->pluck('name_en')->implode(', '));
        }
        if ($rules['max_seats_total'] !== null && $seats > $rules['max_seats_total']) {
            $violations[] = $this->violation('seats_over_cap', [], "المقاعد {$seats} تتجاوز الحد {$rules['max_seats_total']}", "Seats {$seats} exceed the cap of {$rules['max_seats_total']}");
        }
        if ($rules['max_hours_total'] !== null && $hours > $rules['max_hours_total']) {
            $violations[] = $this->violation('hours_over_cap', [], "الساعات {$hours} تتجاوز الحد {$rules['max_hours_total']}", "Hours {$hours} exceed the cap of {$rules['max_hours_total']}");
        }
        $untyped = $active->whereNull('program_type');
        $warnings = $untyped->isEmpty() ? [] : [$this->violation('type_missing', $untyped->pluck('id')->all(), "بنود بلا نوع برنامج: {$untyped->count()}", "Items without a programme type: {$untyped->count()}")];

        return [
            'caps' => ['seats' => ['used' => $seats, 'max' => $rules['max_seats_total']], 'hours' => ['used' => $hours, 'max' => $rules['max_hours_total']]],
            'by_type' => collect(ProgramTypes::ALL)->mapWithKeys(fn ($t) => [$t => $active->where('program_type', $t)->count()])->all() + ['missing' => $untyped->count()],
            'by_category' => $active->groupBy(fn ($i) => $i->category_id ?? '')->map(fn ($g, $id) => ['id' => $id ?: null, 'name_ar' => $categories[$id]->name_ar ?? null, 'name_en' => $categories[$id]->name_en ?? null, 'count' => $g->count(), 'mandatory' => in_array($id, $rules['mandatory_categories'], true)])->values()->all(),
            'mandatory' => $mandatory->all(),
            'allowed_types' => $rules['program_types'],
            'violations' => $violations,
            'warnings' => $warnings,
            'excluded_needs' => $this->excludedNeeds($plan, $rules),
            'deferred' => $items->where('status', 'deferred')->count(),
            'exception' => $plan->rule_exception,
        ];
    }

    /** @return Collection<string, Collection<int, TrainingNeed>> needs not yet planned, by topic */
    private function needBuckets(array $taken): Collection
    {
        return TrainingNeed::with('program.category')->whereIn('status', self::NEED_STATUSES)->whereNotIn('id', $taken)->get()
            ->groupBy(fn ($n) => $n->skill_id ?: mb_strtolower(trim($n->skill_name)));
    }

    private function bucketProgram(Collection $bucket): ?Program
    {
        return $bucket->pluck('program')->filter()->first();
    }

    private function bucketType(Collection $bucket): ?string
    {
        return $this->bucketProgram($bucket)?->effectiveType();
    }

    /** No list means every type; an item without a type is allowed but reported as "type missing". */
    private function typeAllowed(?string $type, array $rules): bool
    {
        return ! $rules['program_types'] || $type === null || in_array($type, $rules['program_types'], true);
    }

    private function excludedRow(Collection $bucket): array
    {
        return ['need_ids' => $bucket->pluck('id')->all(), 'title' => trim($bucket->first()->skill_name), 'program_type' => $this->bucketType($bucket), 'seats' => (int) $bucket->sum('employees_count'), 'reason' => 'type_not_allowed'];
    }

    private function excludedNeeds(TrainingPlan $plan, array $rules): array
    {
        if (! $rules['program_types'] || $plan->status !== TrainingPlan::DRAFT) {
            return [];
        }
        $taken = $plan->items()->pluck('source_refs')->flatten()->filter()->values()->all();

        return $this->needBuckets($taken)->reject(fn ($b) => $this->typeAllowed($this->bucketType($b), $rules))->map(fn ($b) => $this->excludedRow($b))->values()->all();
    }

    /** Every mandatory category gets at least one item; without a need, a placeholder for the planner to complete. */
    private function addMandatoryPlaceholders(TrainingPlan $plan, array $rules): int
    {
        $covered = $plan->items()->whereNotIn('status', self::OUT_OF_TOTALS)->pluck('category_id')->filter()->flip();
        $n = 0;
        foreach (ProgramCategory::whereIn('id', $rules['mandatory_categories'])->get() as $category) {
            if (isset($covered[$category->id])) {
                continue;
            }
            $plan->items()->create([
                'title_ar' => $category->name_ar, 'title_en' => $category->name_en, 'category_id' => $category->id, 'program_type' => $category->program_type,
                'priority' => 'high', 'priority_score' => 0, 'planned_groups' => 1, 'planned_seats' => 0, 'planned_hours' => 0, 'source' => 'mandatory', 'source_refs' => [],
                'rationale_ar' => 'فئة إلزامية في خطة هذا العام: أكمل تفاصيل البرنامج والمقاعد والساعات.', 'rationale_en' => 'A mandatory category this year: complete the programme, seats and hours.',
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Seat and hour caps: generated items are kept in priority order while they fit, the rest are deferred with the reason
     * (an item larger than what is left never pushes out smaller ones behind it). Manual, mandatory and emergency items are
     * the planner's decisions and always count first. Items deferred by an earlier run come back when the caps allow.
     */
    private function applyCaps(TrainingPlan $plan, array $rules): int
    {
        $maxSeats = $rules['max_seats_total'];
        $maxHours = $rules['max_hours_total'];
        $items = $plan->items()->get();
        $candidate = fn (TrainingPlanItem $i) => in_array($i->source, ['needs', 'carry_over'], true) && ($i->status === 'planned' || ($i->status === 'deferred' && $i->deferred_reason !== null));
        $fixed = $items->reject($candidate)->whereNotIn('status', self::OUT_OF_TOTALS);
        $seats = (int) $fixed->sum('planned_seats');
        $hours = (float) $fixed->sum('planned_hours');
        $n = 0;
        foreach ($items->filter($candidate)->sortByDesc('priority_score') as $item) {
            $overSeats = $maxSeats !== null && $seats + (int) $item->planned_seats > $maxSeats;
            $overHours = $maxHours !== null && $hours + (float) $item->planned_hours > $maxHours;
            if ($overSeats || $overHours) {
                $item->update(['status' => 'deferred', 'deferred_reason' => $overSeats ? 'seats_over_cap' : 'hours_over_cap']);
                $n++;

                continue;
            }
            if ($item->status === 'deferred') {
                $item->update(['status' => 'planned', 'deferred_reason' => null]);
            }
            $seats += (int) $item->planned_seats;
            $hours += (float) $item->planned_hours;
        }

        return $n;
    }

    private function violation(string $code, array $itemIds, string $ar, string $en): array
    {
        return ['code' => $code, 'item_ids' => $itemIds, 'message' => ['ar' => $ar, 'en' => $en]];
    }

    private function rulesList(array $violations): string
    {
        return collect($violations)->map(fn ($v) => $v['message'][app()->getLocale() === 'en' ? 'en' : 'ar'])->implode(app()->getLocale() === 'en' ? '; ' : '؛ ');
    }

    private function recordException(TrainingPlan $plan, string $reason, array $violations, User $by, string $stage): void
    {
        $plan->update(['rule_exception' => ['reason' => $reason, 'violations' => $violations, 'stage' => $stage, 'by' => $by->id, 'by_name' => $by->displayName(), 'at' => now()->toIso8601String()]]);
        AuditLog::create(['user_id' => $by->id, 'action' => 'plan_rule_exception', 'auditable_type' => TrainingPlan::class, 'auditable_id' => $plan->id,
            'new_values' => ['reason' => $reason, 'violations' => $violations, 'stage' => $stage], 'ip_address' => request()?->ip()]);
    }

    public function addItem(TrainingPlan $plan, array $data, User $by): TrainingPlanItem
    {
        $this->assertEditable($plan);
        $emergency = (bool) ($data['is_emergency'] ?? false);
        if ($plan->isBaselined() && ! filled($data['reason'] ?? null)) {
            throw new BusinessRuleException(__('messages.plans.reason_required'), 'reason_required');
        }
        $item = $plan->items()->create($this->withType(array_diff_key($data, ['reason' => 1])) + ['source' => $emergency ? 'emergency' : 'manual']);
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
        $data = $this->withType($data);
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

    public function submit(TrainingPlan $plan, User $by, ?string $exceptionReason = null): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::DRAFT]);
        if (! $plan->items()->exists()) {
            throw new BusinessRuleException(__('messages.plans.empty'), 'plan_empty');
        }
        $violations = $this->compliance($plan)['violations'];
        if ($violations) {
            // Only the head of planning (approval right) may go ahead, and only with the reason written down.
            if (! filled($exceptionReason) || ! $by->hasPermission('plans.approve')) {
                throw new BusinessRuleException(__('messages.plans.rules_broken', ['list' => $this->rulesList($violations)]), 'plan_rules_broken', ['violations' => $violations]);
            }
            $this->recordException($plan, trim($exceptionReason), $violations, $by, 'submit');
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

    public function approve(TrainingPlan $plan, User $by, ?string $signedPath = null, ?string $exceptionReason = null): TrainingPlan
    {
        $this->expect($plan, [TrainingPlan::IN_REVIEW]);
        $violations = $this->compliance($plan)['violations'];
        if ($violations) {
            if (! filled($exceptionReason)) {
                throw new BusinessRuleException(__('messages.plans.exception_required', ['list' => $this->rulesList($violations)]), 'exception_reason_required', ['violations' => $violations]);
            }
            $this->recordException($plan, trim($exceptionReason), $violations, $by, 'approve');
        }
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
        $items = $plan->items()->where('status', '!=', 'deferred')->with(['groups' => fn ($q) => $q->withCount(['registrations as taken' => fn ($r) => $r->whereIn('status', Registration::SEAT_HOLDING)])])->get();
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

    /** A planned item takes its programme's (or category's) type unless one is given. */
    private function withType(array $data): array
    {
        if (array_key_exists('program_type', $data)) {
            return $data;
        }
        if (! empty($data['program_id'])) {
            $data['program_type'] = Program::with('category')->find($data['program_id'])?->effectiveType();
        } elseif (! empty($data['category_id'])) {
            $data['program_type'] = ProgramCategory::whereKey($data['category_id'])->value('program_type');
        }

        return $data;
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
