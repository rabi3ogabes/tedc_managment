<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Registration;
use App\Models\Skill;
use App\Models\TrainingNeed;
use App\Models\User;
use App\Services\NeedsSurveys\SurveyAudience;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Smart program creation. Turns collected training needs (or a manual choice of topic and audience)
 * into a ready-to-review program: title, hours, capacity, audience, dates that respect the calendar,
 * a session plan with the best free room and trainers - then creates everything in one transaction.
 */
class ProgramPlanner
{
    public const OPEN_NEED_STATUSES = ['submitted', 'under_review', 'approved'];

    public const SESSION_HOURS = 3;

    public const COHORT_SIZE = 30;

    public function __construct(
        private readonly CalendarService $calendar,
        private readonly RoomService $rooms,
        private readonly TrainerService $trainers,
        private readonly SurveyAudience $audience,
        private readonly AudienceRules $rules,
        private readonly NotificationService $notifications,
        private readonly RegistrationService $registrations,
    ) {}

    /**
     * Open needs grouped by topic, most urgent first.
     *
     * @return Collection<int, array>
     */
    public function needsPool(): Collection
    {
        $open = TrainingNeed::with(['skill', 'school:id,name_ar,name_en', 'targetJobTitle:id,name_ar,name_en', 'survey:id,title'])
            ->whereIn('status', self::OPEN_NEED_STATUSES)->whereNull('program_id')->get();

        $covering = Program::with('skills:id')->whereIn('status', Program::VISIBLE)->withCount(['registrations as seats_taken' => fn ($q) => $q->whereIn('status', Registration::SEAT_HOLDING)])->get();

        return $open->groupBy(fn (TrainingNeed $n) => $n->skill_id ?? 'custom:'.Str::slug($n->skill_name))
            ->map(function (Collection $rows, string $key) use ($covering) {
                $first = $rows->first();
                $skillId = $first->skill_id;
                $weight = $rows->sum(fn ($n) => (TrainingNeed::PRIORITY_WEIGHT[$n->priority] ?? 1) * $n->employees_count);
                $top = $rows->sortByDesc(fn ($n) => TrainingNeed::PRIORITY_WEIGHT[$n->priority] ?? 1)->first()->priority;

                return [
                    'key' => $key,
                    'skill_id' => $skillId,
                    'skill' => $first->skill?->translate('name') ?? $first->skill_name,
                    'skill_code' => $first->skill?->code,
                    'category' => $first->skill?->category,
                    'need_ids' => $rows->pluck('id')->values(),
                    'requests' => $rows->count(),
                    'employees' => (int) $rows->sum('employees_count'),
                    'schools' => $rows->pluck('school_id')->unique()->count(),
                    'school_names' => $rows->map(fn ($n) => $n->school?->translate('name'))->filter()->unique()->take(5)->values(),
                    'priority' => $top,
                    'score' => (int) $weight,
                    'reasons' => $rows->pluck('reason')->filter()->unique()->take(3)->values(),
                    'job_titles' => $rows->map(fn ($n) => $n->targetJobTitle?->translate('name'))->filter()->unique()->values(),
                    'from_survey' => $rows->contains(fn ($n) => $n->survey_id !== null),
                    'oldest_at' => $rows->min('created_at')?->toIso8601String(),
                    'existing_programs' => $skillId ? $covering->filter(fn (Program $p) => $p->skills->contains('id', $skillId))->map(fn (Program $p) => [
                        'id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title'), 'status' => $p->status, 'seats_available' => max(0, $p->capacity - $p->seats_taken),
                    ])->values() : collect(),
                ];
            })->sortByDesc('score')->values();
    }

    /**
     * A complete suggestion for a program built from the given needs (or one skill).
     *
     * @param  string[]  $needIds
     */
    public function draft(array $needIds = [], ?string $skillId = null, ?CarbonImmutable $from = null, bool $remote = false): array
    {
        $needs = TrainingNeed::with(['skill', 'school:id,name_ar,name_en', 'targetJobTitle'])
            ->when($needIds, fn ($q) => $q->whereIn('id', $needIds))
            ->when(! $needIds && $skillId, fn ($q) => $q->where('skill_id', $skillId)->whereIn('status', self::OPEN_NEED_STATUSES)->whereNull('program_id'))
            ->get();

        $skill = $needs->groupBy('skill_id')->sortByDesc(fn ($g) => $g->sum('employees_count'))->keys()->first();
        $skillModel = $skill ? Skill::find($skill) : ($skillId ? Skill::find($skillId) : null);
        $topicAr = $skillModel?->name_ar ?? $needs->first()?->skill_name ?? '';
        $topicEn = $skillModel?->name_en ?? $topicAr;

        $employees = (int) $needs->sum('employees_count');
        $cohorts = max(1, (int) ceil($employees / self::COHORT_SIZE));
        $capacity = $employees ? min(self::COHORT_SIZE, (int) (ceil($employees / $cohorts / 5) * 5)) : self::COHORT_SIZE;
        $critical = $needs->contains(fn ($n) => in_array($n->priority, ['critical', 'high'], true));
        $hours = $critical ? 15.0 : 12.0;

        $category = ProgramCategory::where('slug', $skillModel?->category)->first();
        $audience = SurveyAudience::clean([
            'school_ids' => $needs->pluck('school_id')->unique()->all(),
            'job_title_ids' => $needs->pluck('target_job_title_id')->filter()->unique()->all(),
        ]);

        $sessions = $this->plan($hours, $from ?? CarbonImmutable::today()->addDays(14), $capacity, $skillModel ? [$skillModel->code] : [], '09:00', self::SESSION_HOURS, $remote);
        $first = $sessions[0] ?? null;
        $last = $sessions ? end($sessions) : null;

        return [
            'code' => $this->nextCode($category?->slug),
            'title_ar' => $topicAr ? "برنامج {$topicAr}" : '',
            'title_en' => $topicEn ? "{$topicEn} Program" : '',
            'summary_ar' => $topicAr ? "برنامج تدريبي لتنمية مهارة «{$topicAr}» لدى الفئة المستهدفة، مبني على الاحتياجات التدريبية المرفوعة." : '',
            'summary_en' => $topicEn ? "A training program developing \"{$topicEn}\" for the target group, built from the collected training needs." : '',
            'category_id' => $category?->id,
            'objectives' => $needs->pluck('reason')->filter()->unique()->take(4)->map(fn ($r) => Str::limit($r, 200))->values()->all(),
            'skills' => $skillModel ? [['id' => $skillModel->id, 'name' => $skillModel->translate('name'), 'target_level' => 4]] : [],
            'delivery_mode' => $remote ? 'online' : 'in_person',
            'level' => 'intermediate',
            'total_hours' => $hours,
            'capacity' => $capacity,
            'cohorts' => $cohorts,
            'demand' => $employees,
            'min_attendance_percent' => 80,
            'requires_tasks' => true,
            'requires_evaluation' => true,
            'registration_modes' => Program::MODES,
            'start_date' => $first ? substr($first['starts_at'], 0, 10) : null,
            'end_date' => $last ? substr($last['starts_at'], 0, 10) : null,
            'registration_opens_at' => now()->toIso8601String(),
            'registration_closes_at' => $first ? CarbonImmutable::parse($first['starts_at'])->subDays(2)->endOfDay()->toIso8601String() : null,
            'audience' => $audience,
            'audience_summary' => $this->audience->describe($audience),
            'need_ids' => $needs->pluck('id')->values()->all(),
            'sessions' => $sessions,
            'trainers' => $this->trainerSuggestions($skillModel ? [$skillModel->code] : [], $first),
            'priority' => $needs->sortByDesc(fn ($n) => TrainingNeed::PRIORITY_WEIGHT[$n->priority] ?? 1)->first()?->priority,
        ];
    }

    /**
     * Sessions of {@see self::SESSION_HOURS} hours on consecutive days that are open for training, each with the best free room.
     *
     * @param  string[]  $specializations
     * @return list<array>
     */
    public function plan(float $totalHours, CarbonImmutable $from, int $capacity, array $specializations = [], string $startTime = '09:00', int $sessionHours = self::SESSION_HOURS, bool $remote = false): array
    {
        $count = max(1, (int) ceil($totalHours / $sessionHours));
        $window = $this->calendar->range($from, $from->addDays(max(60, $count * 4)), false)['days'];
        $open = collect($window)->where('training_allowed', true)->pluck('date')->take($count)->values();

        $sessions = [];
        $remaining = $totalHours;
        foreach ($open as $i => $date) {
            $hours = min($sessionHours, $remaining);
            $remaining -= $hours;
            $start = CarbonImmutable::parse("{$date} {$startTime}");
            $end = $start->addMinutes((int) round($hours * 60));
            $best = $remote ? null : $this->rooms->suggest($start, $end, ['capacity' => $capacity])->first(fn ($r) => $r['available'] && $r['fits_capacity']);

            $sessions[] = [
                'sequence' => $i + 1,
                'title_ar' => 'الجلسة '.($i + 1),
                'title_en' => 'Session '.($i + 1),
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'training_room_id' => $best['room']->id ?? null,
                'room' => $best ? $best['room']->translate('name') : null,
                'trainer_id' => null,
                'mode' => $remote ? 'online' : 'in_person',
            ];
        }

        return $sessions;
    }

    /** @param  string[]  $specializations */
    public function trainerSuggestions(array $specializations, ?array $firstSession): array
    {
        $slot = $firstSession ? ['starts_at' => CarbonImmutable::parse($firstSession['starts_at']), 'ends_at' => CarbonImmutable::parse($firstSession['ends_at'])] : [];

        return $this->trainers->suggest(['specializations' => $specializations] + $slot, 6)->map(fn ($r) => [
            'id' => $r['trainer']->id, 'name' => $r['trainer']->translate('name'), 'source' => $r['trainer']->source, 'source_label' => $r['trainer']->sourceLabel(),
            'organization' => $r['trainer']->partner?->translate('name') ?? $r['trainer']->organization, 'rating' => $r['trainer']->rating,
            'score' => $r['score'], 'available' => $r['available'], 'matched' => $r['matched_specializations'],
        ])->all();
    }

    public function nextCode(?string $categorySlug): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', (string) $categorySlug) ?: 'PRG', 0, 3));
        $number = 100;
        do {
            $code = $prefix.'-'.(++$number);
        } while (Program::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Creates the program with its audience rules, skills, trainers and session plan in one transaction.
     * Sessions are checked against the calendar, room bookings and trainer availability.
     *
     * @param  array<string, mixed>  $data  validated program fields plus audience, need_ids, sessions, trainers ...
     */
    public function create(array $data, User $actor, ?string $approvalReason = null): Program
    {
        return DB::transaction(function () use ($data, $actor, $approvalReason) {
            $fields = collect($data)->only([
                'code', 'category_id', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'description_ar', 'description_en', 'objectives', 'delivery_mode', 'level',
                'total_hours', 'capacity', 'min_attendance_percent', 'requires_tasks', 'requires_evaluation', 'start_date', 'end_date',
                'registration_opens_at', 'registration_closes_at', 'registration_modes', 'status', 'is_featured',
                'remote', 'certificate_template_id', 'trainer_certificate_template_id',
            ])->all();
            $needIds = $data['need_ids'] ?? [];

            $fields['code'] = $fields['code'] ?? $this->nextCode(null);
            $program = Program::create($fields + [
                'status' => Program::STATUS_DRAFT,
                'source_type' => $needIds ? 'needs' : 'manual',
                'created_by' => $actor->id,
            ]);

            if (! empty($data['skills'])) {
                $program->skills()->sync(collect($data['skills'])->mapWithKeys(fn ($s) => [$s['id'] => ['target_level' => $s['target_level'] ?? 4]]));
            }
            if (! empty($data['trainers'])) {
                $program->trainers()->sync(collect($data['trainers'])->mapWithKeys(fn ($t) => [$t['id'] => ['role' => $t['role'] ?? 'lead']]));
            }
            $this->rules->apply($program, $data['audience'] ?? null);

            foreach (array_values($data['sessions'] ?? []) as $i => $session) {
                $start = CarbonImmutable::parse($session['starts_at']);
                $end = CarbonImmutable::parse($session['ends_at']);
                if ($approvalReason) {
                    $this->calendar->approveSpan($start, $end, $approvalReason, $actor->id);
                }
                $this->calendar->assertTrainingAllowed($start, $end);
                if (! empty($session['training_room_id']) && ! (($data['delivery_mode'] ?? '') === 'online' || ($session['mode'] ?? null) === 'online')) {
                    $this->rooms->assertBookable($session['training_room_id'], $start, $end);
                }
                $trainerId = $session['trainer_id'] ?? collect($data['trainers'] ?? [])->firstWhere('role', 'lead')['id'] ?? ($data['trainers'][0]['id'] ?? null);
                if ($trainerId) {
                    $this->trainers->assertAssignable($trainerId, $start, $end);
                }

                // Remote programs: sessions are online meetings (no room), with the program's link unless one is given.
                $remote = $data['remote'] ?? [];
                $online = ($data['delivery_mode'] ?? 'in_person') === 'online' || ($session['mode'] ?? null) === 'online';

                $program->sessions()->create([
                    'mode' => $online ? 'online' : 'in_person',
                    'online_url' => $online ? ($session['online_url'] ?? $remote['join_url'] ?? null) : null,
                    'online_platform' => $online ? ($session['online_platform'] ?? $remote['platform'] ?? null) : null,
                    'online_passcode' => $online ? ($session['online_passcode'] ?? $remote['passcode'] ?? null) : null,
                    'sequence' => $session['sequence'] ?? $i + 1,
                    'title_ar' => $session['title_ar'] ?? 'الجلسة '.($i + 1),
                    'title_en' => $session['title_en'] ?? 'Session '.($i + 1),
                    'starts_at' => $start, 'ends_at' => $end,
                    'training_room_id' => $online ? null : ($session['training_room_id'] ?? null),
                    'trainer_id' => $trainerId,
                    'location_text' => $session['location_text'] ?? null,
                ]);
            }

            if ($needIds) {
                TrainingNeed::whereIn('id', $needIds)->update(['status' => 'planned', 'program_id' => $program->id, 'reviewed_by' => $actor->id]);
            }

            return $program;
        });
    }

    /** In-app invitation to everyone in the program audience. @return int people notified */
    public function invite(Program $program): int
    {
        $userIds = $this->audience->query($program->audience ?? [])->pluck('employees.user_id');

        return $this->notifications->broadcast(
            $userIds, 'program.invite',
            ['ar' => 'برنامج تدريبي جديد يناسبك', 'en' => 'A new training program for you'],
            ['ar' => "«{$program->title_ar}» متاح للتسجيل ضمن الفئة المستهدفة.", 'en' => "\"{$program->title_en}\" is open to your group."],
            ['program_id' => $program->id, 'program_code' => $program->code],
        );
    }

    /**
     * Nominates every audience member who is not registered yet. Stops filling seats once the program is full
     * (the rest go to the waiting list, as with any nomination).
     *
     * @return array{nominated: int, already: int, failed: int}
     */
    public function nominateAudience(Program $program, User $actor): array
    {
        $result = ['nominated' => 0, 'already' => 0, 'failed' => 0];
        $registered = $program->registrations()->pluck('employee_id')->flip();

        $this->audience->query($program->audience ?? [])->orderBy('employees.employee_no')->limit(500)->get()
            ->each(function (Employee $employee) use ($program, $actor, $registered, &$result) {
                if ($registered->has($employee->id)) {
                    $result['already']++;

                    return;
                }
                try {
                    $this->registrations->nominate($program, $employee, $actor, 'training_center', __('messages.program_builder.nomination_note'), true);
                    $result['nominated']++;
                } catch (\Throwable) {
                    $result['failed']++;
                }
            });

        return $result;
    }
}
