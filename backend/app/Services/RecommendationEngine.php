<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\Program;
use App\Models\Registration;
use App\Models\TrainingNeed;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;
use Illuminate\Support\Collection;

/**
 * Smart Recommendation Engine — "Recommended Training For You".
 *
 * A transparent, explainable scoring model (0..100) combining:
 *   skill gap        35  program target skills the employee lacks or holds below target level
 *   role fit         25  target group / eligibility match for the employee's job
 *   school needs     20  skills requested by the employee's school in training needs
 *   career stage     10  program level matches the employee's experience
 *   peer rating      10  satisfaction reported by previous participants
 * Programs the employee is ineligible for, already registered in or completed are excluded.
 */
class RecommendationEngine
{
    public function __construct(private readonly EligibilityEngine $eligibility) {}

    /** @return Collection<int, array{program: Program, score: float, reasons: array<int, string>}> */
    public function forEmployee(Employee $employee, int $limit = 6): Collection
    {
        $employee->loadMissing(['skills', 'school', 'jobTitle']);

        $excluded = Registration::where('employee_id', $employee->id)
            ->whereNotIn('status', [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED])
            ->pluck('program_id');

        $programs = Program::with(['skills', 'targetGroups', 'eligibilityRules', 'category'])
            ->whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN])
            ->whereNotIn('id', $excluded)
            ->get();

        if ($programs->isEmpty()) {
            return collect();
        }

        $skillLevels = $employee->skills->mapWithKeys(fn ($s) => [$s->id => (int) $s->pivot->level]);
        $schoolNeeds = $this->schoolNeedWeights($employee->school_id);
        $ratings = Evaluation::whereIn('program_id', $programs->pluck('id'))
            ->selectRaw('program_id, avg(satisfaction_score) as score')
            ->groupBy('program_id')
            ->pluck('score', 'program_id');

        $context = EmployeeContext::fromEmployee($employee);

        return $programs
            ->map(function (Program $program) use ($employee, $skillLevels, $schoolNeeds, $ratings, $context) {
                $eligibility = $this->eligibility->evaluate($program, $employee, $context);
                if (! $eligibility->eligible) {
                    return null;
                }

                $reasons = [];
                $score = 0.0;

                // Skill gap
                $gaps = $program->skills->filter(fn ($skill) => ($skillLevels[$skill->id] ?? 0) < (int) $skill->pivot->target_level);
                if ($program->skills->isNotEmpty()) {
                    $score += 35 * $gaps->count() / $program->skills->count();
                }
                if ($gaps->isNotEmpty()) {
                    $reasons[] = __('recommendations.skill_gap', ['skills' => $gaps->take(3)->map->translate('name')->implode('، ')]);
                }

                // Role fit
                if ($program->targetGroups->isNotEmpty()) {
                    $score += 25;
                    $reasons[] = __('recommendations.role_fit', ['role' => $employee->jobTitle?->translate('name') ?? '']);
                } elseif ($program->eligibilityRules->isNotEmpty()) {
                    $score += 15;
                } else {
                    $score += 8;
                }

                // School needs
                $need = $program->skills->sum(fn ($skill) => $schoolNeeds[$skill->id] ?? 0);
                if ($need > 0) {
                    $score += min(20, $need * 5);
                    $reasons[] = __('recommendations.school_need');
                }

                // Career stage
                if ($this->levelFits($program->level, (float) $employee->experience_years)) {
                    $score += 10;
                    $reasons[] = __('recommendations.career_stage');
                }

                // Peer rating
                if (($rating = $ratings[$program->id] ?? null) !== null) {
                    $score += 10 * ((float) $rating / 100);
                    if ($rating >= 85) {
                        $reasons[] = __('recommendations.highly_rated', ['score' => round($rating)]);
                    }
                }

                return ['program' => $program, 'score' => round(min(100, $score), 1), 'reasons' => $reasons];
            })
            ->filter()
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Employees best suited for a program — used by admins to target nominations.
     */
    public function candidatesForProgram(Program $program, ?string $schoolId = null, int $limit = 50): Collection
    {
        $program->loadMissing(['skills', 'targetGroups', 'eligibilityRules']);
        $registered = $program->registrations()->pluck('employee_id');

        return Employee::with(['user:id,name,name_ar', 'school:id,name_ar,name_en', 'jobTitle', 'skills'])
            ->where('status', 'active')
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->whereNotIn('id', $registered)
            ->limit(500)
            ->get()
            ->map(function (Employee $employee) use ($program) {
                if (! $this->eligibility->evaluate($program, $employee)->eligible) {
                    return null;
                }
                $levels = $employee->skills->mapWithKeys(fn ($s) => [$s->id => (int) $s->pivot->level]);
                $gap = $program->skills->sum(fn ($s) => max(0, (int) $s->pivot->target_level - ($levels[$s->id] ?? 0)));

                return ['employee' => $employee, 'gap' => $gap];
            })
            ->filter()
            ->sortByDesc('gap')
            ->take($limit)
            ->values();
    }

    /** @return array<string, int> skill_id => weighted demand from the school's open needs */
    private function schoolNeedWeights(?string $schoolId): array
    {
        if (! $schoolId) {
            return [];
        }

        return TrainingNeed::where('school_id', $schoolId)
            ->whereIn('status', ['submitted', 'under_review', 'approved', 'planned'])
            ->whereNotNull('skill_id')
            ->get()
            ->groupBy('skill_id')
            ->map(fn ($needs) => $needs->sum(fn ($n) => TrainingNeed::PRIORITY_WEIGHT[$n->priority] ?? 1))
            ->all();
    }

    private function levelFits(string $level, float $experience): bool
    {
        return match ($level) {
            'beginner' => $experience < 3,
            'intermediate' => $experience >= 2 && $experience <= 10,
            'advanced' => $experience >= 7,
            default => false,
        };
    }
}
