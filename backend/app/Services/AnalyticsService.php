<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\ImpactSurvey;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\School;
use App\Models\Skill;
use App\Models\TrainingNeed;
use App\Support\AccessScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations for the admin, executive, geographic and training-needs dashboards.
 * Queries are written to be portable (PostgreSQL in production, SQLite in tests);
 * time bucketing happens in PHP over bounded windows.
 */
class AnalyticsService
{
    private const ACTIVE = [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED];

    public function dashboard(?AccessScope $scope = null): array
    {
        $scope ??= AccessScope::ministry();
        $employees = $scope->constrainEmployees(Employee::query());
        $registrations = $scope->constrainThroughEmployee(Registration::query());

        $minutes = $scope->constrainThroughEmployee(Attendance::query())->sum('minutes_attended');

        return [
            'kpis' => [
                'total_schools' => $scope->constrainSchoolColumn(School::where('status', 'active'), 'id')->count(),
                'total_employees' => (clone $employees)->count(),
                'active_programs' => Program::whereIn('status', [Program::STATUS_REGISTRATION_OPEN, Program::STATUS_IN_PROGRESS, Program::STATUS_PUBLISHED])->count(),
                'participants' => (clone $registrations)->whereIn('status', self::ACTIVE)->distinct()->count('employee_id'),
                'training_hours' => round($minutes / 60),
                'certificates_issued' => $scope->constrainThroughEmployee(Certificate::where('status', 'valid'))->count(),
                'impact_score' => round((float) (clone $registrations)->whereNotNull('impact_score')->avg('impact_score'), 1),
                'pending_registrations' => (clone $registrations)->where('status', Registration::STATUS_PENDING)->count(),
            ],
            'trend' => $this->monthlyTrend($registrations),
            'status_distribution' => (clone $registrations)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'by_category' => Program::with('category')->get()->groupBy(fn ($p) => $p->category?->translate('name') ?? '—')->map->count(),
            'upcoming_sessions' => ProgramSession::with('program:id,title_ar,title_en,code', 'trainer:id,name_ar,name_en')
                ->where('starts_at', '>=', now())->orderBy('starts_at')->limit(6)->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->translate('title'),
                    'program' => $s->program->translate('title'),
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'trainer' => $s->trainer?->translate('name'),
                ]),
            'top_programs' => $this->topPrograms(5),
        ];
    }

    public function executive(): array
    {
        $totalEmployees = max(1, Employee::where('status', 'active')->count());
        $trained = Registration::where('status', Registration::STATUS_COMPLETED)->distinct()->count('employee_id');
        $totalSchools = max(1, School::where('status', 'active')->count());
        $participatingSchools = Employee::whereIn('id', Registration::whereIn('status', self::ACTIVE)->select('employee_id'))
            ->distinct()->count('school_id');
        $minutes = Attendance::sum('minutes_attended');

        $skillGrowth = EmployeeSkill::where('source', 'training')
            ->where('verified_at', '>=', now()->subMonths(12))
            ->get(['skill_id', 'verified_at']);
        $skills = Skill::whereIn('id', $skillGrowth->pluck('skill_id')->unique())->get()->keyBy('id');

        return [
            'coverage' => [
                'training_coverage' => round($trained / $totalEmployees * 100, 1),
                'school_participation' => round($participatingSchools / $totalSchools * 100, 1),
                'avg_hours_per_employee' => round($minutes / 60 / $totalEmployees, 1),
                'certificates' => Certificate::where('status', 'valid')->count(),
                'impact_score' => round((float) Registration::whereNotNull('impact_score')->avg('impact_score'), 1),
                'application_rate' => $this->applicationRate(),
            ],
            'employee_development' => [
                'trained_employees' => $trained,
                'untrained_employees' => $totalEmployees - $trained,
                'skills_acquired' => $skillGrowth->count(),
            ],
            'skill_trends' => $skillGrowth->groupBy('skill_id')
                ->map(fn ($rows, $id) => [
                    'skill' => $skills[$id]?->translate('name'),
                    'total' => $rows->count(),
                    'series' => $this->bucketByMonth($rows->pluck('verified_at'), 6),
                ])
                ->sortByDesc('total')->take(8)->values(),
            'category_performance' => Program::with('category')
                ->withCount(['registrations as completed' => fn ($q) => $q->where('status', Registration::STATUS_COMPLETED)])
                ->withAvg(['registrations as impact' => fn ($q) => $q->whereNotNull('impact_score')], 'impact_score')
                ->get()
                ->groupBy(fn ($p) => $p->category?->translate('name') ?? '—')
                ->map(fn ($programs, $name) => [
                    'category' => $name,
                    'programs' => $programs->count(),
                    'completed' => $programs->sum('completed'),
                    'impact' => round((float) $programs->whereNotNull('impact')->avg('impact'), 1),
                ])->values(),
            'top_programs' => $this->topPrograms(8),
            'trend' => $this->monthlyTrend(Registration::query()),
        ];
    }

    public function geographic(): array
    {
        $schools = School::withCount('employees')->where('status', 'active')->get();

        $trainedBySchool = Employee::join('registrations', 'registrations.employee_id', '=', 'employees.id')
            ->where('registrations.status', Registration::STATUS_COMPLETED)
            ->select('employees.school_id', DB::raw('count(distinct employees.id) as trained'))
            ->groupBy('employees.school_id')
            ->pluck('trained', 'school_id');

        $participantsBySchool = Employee::join('registrations', 'registrations.employee_id', '=', 'employees.id')
            ->whereIn('registrations.status', self::ACTIVE)
            ->select('employees.school_id', DB::raw('count(distinct employees.id) as participants'))
            ->groupBy('employees.school_id')
            ->pluck('participants', 'school_id');

        $needsBySchool = TrainingNeed::whereIn('status', ['submitted', 'under_review', 'approved', 'planned'])
            ->select('school_id', DB::raw('sum(employees_count) as requested'))
            ->groupBy('school_id')
            ->pluck('requested', 'school_id');

        $points = $schools->map(fn (School $s) => [
            'id' => $s->id,
            'name' => $s->translate('name'),
            'region' => $s->region,
            'type' => $s->type,
            'stage' => $s->stage,
            'lat' => $s->latitude,
            'lng' => $s->longitude,
            'employees' => $s->employees_count,
            'participants' => (int) ($participantsBySchool[$s->id] ?? 0),
            'trained' => (int) ($trainedBySchool[$s->id] ?? 0),
            'coverage' => $s->employees_count ? round(($trainedBySchool[$s->id] ?? 0) / $s->employees_count * 100, 1) : 0,
            'open_needs' => (int) ($needsBySchool[$s->id] ?? 0),
        ]);

        $regions = $points->groupBy('region')->map(fn (Collection $rows, $region) => [
            'region' => $region,
            'schools' => $rows->count(),
            'participating_schools' => $rows->where('participants', '>', 0)->count(),
            'employees' => $rows->sum('employees'),
            'trained' => $rows->sum('trained'),
            'coverage' => $rows->sum('employees') ? round($rows->sum('trained') / $rows->sum('employees') * 100, 1) : 0,
            'gap' => $rows->sum('employees') - $rows->sum('trained'),
            'open_needs' => $rows->sum('open_needs'),
        ])->sortBy('coverage')->values();

        return [
            'regions' => $regions,
            'schools' => $points->values(),
            'lowest_coverage' => $points->where('employees', '>', 0)->sortBy('coverage')->take(10)->values(),
        ];
    }

    public function trainingNeeds(): array
    {
        $needs = TrainingNeed::with(['skill', 'school:id,name_ar,name_en,region'])->get();
        $open = $needs->whereIn('status', ['submitted', 'under_review', 'approved', 'planned']);

        $bySkill = $open->groupBy(fn ($n) => $n->skill_id ?? 'custom:'.mb_strtolower($n->skill_name))
            ->map(fn (Collection $rows) => [
                'skill_id' => $rows->first()->skill_id,
                'skill' => $rows->first()->skill?->translate('name') ?? $rows->first()->skill_name,
                'requests' => $rows->count(),
                'employees' => $rows->sum('employees_count'),
                'schools' => $rows->pluck('school_id')->unique()->count(),
                'priority_score' => $rows->sum(fn ($n) => (TrainingNeed::PRIORITY_WEIGHT[$n->priority] ?? 1) * $n->employees_count),
            ])
            ->sortByDesc('priority_score')->values();

        $skillIds = $bySkill->pluck('skill_id')->filter();
        $programsBySkill = Program::with('skills')->whereIn('status', Program::VISIBLE)
            ->whereHas('skills', fn ($q) => $q->whereIn('skills.id', $skillIds))->get();

        $neededPrograms = $programsBySkill->map(function (Program $program) use ($bySkill) {
            $demand = $bySkill->whereIn('skill_id', $program->skills->pluck('id'))->sum('employees');

            return [
                'program_id' => $program->id,
                'program' => $program->translate('title'),
                'demand' => $demand,
                'capacity' => $program->capacity,
                'seats_gap' => max(0, $demand - $program->capacity),
            ];
        })->sortByDesc('demand')->values();

        $uncovered = $bySkill->filter(fn ($s) => ! $s['skill_id'] || ! $programsBySkill->contains(fn ($p) => $p->skills->contains('id', $s['skill_id'])))->values();

        $schoolGaps = $open->groupBy('school_id')->map(fn (Collection $rows) => [
            'school' => $rows->first()->school?->translate('name'),
            'region' => $rows->first()->school?->region,
            'requests' => $rows->count(),
            'employees' => $rows->sum('employees_count'),
            'critical' => $rows->where('priority', 'critical')->count(),
            'skills' => $rows->map(fn ($n) => $n->skill?->translate('name') ?? $n->skill_name)->unique()->values(),
        ])->sortByDesc('employees')->values();

        return [
            'totals' => [
                'requests' => $needs->count(),
                'open' => $open->count(),
                'employees' => $open->sum('employees_count'),
                'fulfilled' => $needs->where('status', 'fulfilled')->count(),
            ],
            'by_priority' => $open->countBy('priority'),
            'by_status' => $needs->countBy('status'),
            'most_requested_skills' => $bySkill->take(10),
            'most_needed_programs' => $neededPrograms->take(10),
            'uncovered_skills' => $uncovered->take(10),
            'school_gaps' => $schoolGaps->take(15),
        ];
    }

    public function topPrograms(int $limit): Collection
    {
        return Program::withCount(['registrations as participants' => fn ($q) => $q->whereIn('status', self::ACTIVE)])
            ->withAvg(['registrations as impact' => fn ($q) => $q->whereNotNull('impact_score')], 'impact_score')
            ->orderByDesc('participants')
            ->limit($limit)
            ->get()
            ->map(fn (Program $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'title' => $p->translate('title'),
                'participants' => $p->participants,
                'impact' => $p->impact ? round($p->impact, 1) : null,
            ]);
    }

    private function applicationRate(): ?float
    {
        $surveys = ImpactSurvey::where('status', 'completed');
        $total = (clone $surveys)->count();

        return $total ? round((clone $surveys)->whereIn('applied_learning', ['yes', 'partially'])->count() / $total * 100, 1) : null;
    }

    private function monthlyTrend($registrations): array
    {
        $since = now()->subMonths(11)->startOfMonth();
        $rows = (clone $registrations)->where('created_at', '>=', $since)->get(['created_at', 'status', 'completed_at']);

        return [
            'registrations' => $this->bucketByMonth($rows->pluck('created_at'), 12),
            'completions' => $this->bucketByMonth($rows->pluck('completed_at')->filter(), 12),
        ];
    }

    /** @return array<int, array{month: string, total: int}> */
    private function bucketByMonth(Collection $dates, int $months): array
    {
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $buckets[now()->startOfMonth()->subMonths($i)->format('Y-m')] = 0;
        }

        foreach ($dates as $date) {
            $key = Carbon::parse($date)->format('Y-m');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        return collect($buckets)->map(fn ($total, $month) => ['month' => $month, 'total' => $total])->values()->all();
    }
}
