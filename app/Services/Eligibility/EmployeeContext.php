<?php

namespace App\Services\Eligibility;

use App\Models\Employee;
use App\Models\EmployeePathProgress;
use App\Models\PdRecognitionRequest;
use App\Models\PerformanceAppraisal;
use App\Models\ProfessionalLicence;
use App\Models\Program;
use App\Models\ProgramEquivalence;
use App\Models\Registration;
use App\Services\AnnualHoursService;

/**
 * Flattened, rule-friendly view of an employee profile.
 */
final class EmployeeContext
{
    public function __construct(
        public readonly ?string $jobTitle,
        public readonly ?string $jobCategory,
        public readonly ?string $department,
        public readonly ?string $schoolType,
        public readonly ?string $schoolStage,
        public readonly ?string $educationStage,
        public readonly ?string $region,
        public readonly float $experienceYears,
        public readonly ?string $qualification,
        /** @var string[] program codes */
        public readonly array $completedPrograms,
        /** @var array<string,int> skill code => level */
        public readonly array $skills,
        public readonly ?string $jobTitleId = null,
        public readonly ?string $departmentId = null,
        public readonly ?string $specialization = null,
        public readonly ?string $gender = null,
        public readonly ?string $nationality = null,
        public readonly ?string $schoolId = null,
        public readonly ?int $age = null,
        public readonly ?float $experienceMoe = null,
        public readonly ?float $experienceOutside = null,
        public readonly ?float $experienceCurrentTitle = null,
        public readonly ?string $gradeLevel = null,
        /** @var string[] */
        public readonly array $subjects = [],
        /** @var string[] */
        public readonly array $gradesTaught = [],
        public readonly ?float $appraisalMin = null,
        public readonly ?float $appraisalAvg = null,
        /** @var string[] program codes completed, plus the codes of their equivalents */
        public readonly array $equivalentCompleted = [],
        public readonly ?bool $hasLicence = null,
        public readonly ?int $licenceLevel = null,
        public readonly ?int $pathLevel = null,
        public readonly ?float $pdHours = null,
    ) {}

    public static function fromEmployee(Employee $employee): self
    {
        $employee->loadMissing(['jobTitle', 'department', 'school', 'skills']);

        $completed = $employee->registrations()
            ->where('registrations.status', Registration::STATUS_COMPLETED)
            ->join('programs', 'programs.id', '=', 'registrations.program_id')
            ->pluck('programs.code')
            ->all();

        $ratings = PerformanceAppraisal::where('employee_id', $employee->id)->where('year', '>=', now()->year - 3)->pluck('rating_code')->map(fn ($c) => ['weak' => 1, 'acceptable' => 2, 'good' => 3, 'very_good' => 4, 'excellent' => 5][$c] ?? null)->filter();
        // A recognised external activity counts as having completed the programs the centre named as its equivalents.
        $recognised = PdRecognitionRequest::where('center_decision', 'approved')->whereHas('activity', fn ($q) => $q->where('employee_id', $employee->id))->pluck('equivalent_program_ids')->flatten()->filter()->unique()->all();
        if ($recognised) {
            $completed = array_values(array_unique(array_merge($completed, Program::whereIn('id', $recognised)->pluck('code')->all())));
        }
        $licences = ProfessionalLicence::where('employee_id', $employee->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', today()))->get();
        $equivalent = $completed;
        if ($completed) {
            $ids = Program::whereIn('code', $completed)->pluck('id')->all();
            $eq = ProgramEquivalence::whereIn('program_id', $ids)->orWhere(fn ($q) => $q->whereIn('equivalent_program_id', $ids)->where('bidirectional', true))->get();
            $other = $eq->flatMap(fn ($e) => [$e->program_id, $e->equivalent_program_id])->unique()->all();
            $equivalent = array_values(array_unique(array_merge($completed, Program::whereIn('id', $other)->pluck('code')->all())));
        }

        return new self(
            jobTitle: $employee->jobTitle?->code,
            jobCategory: $employee->jobTitle?->category,
            department: $employee->department?->code,
            schoolType: $employee->school?->type,
            schoolStage: $employee->school?->stage,
            educationStage: $employee->education_stage,
            region: $employee->school?->region,
            experienceYears: (float) $employee->experience_years,
            qualification: $employee->qualification,
            completedPrograms: $completed,
            skills: $employee->skills->mapWithKeys(fn ($s) => [$s->code => (int) $s->pivot->level])->all(),
            jobTitleId: $employee->job_title_id,
            departmentId: $employee->department_id,
            specialization: $employee->specialization,
            gender: $employee->gender,
            nationality: $employee->nationality,
            schoolId: $employee->school_id,
            age: $employee->age(),
            experienceMoe: $employee->experience_moe_years === null ? null : (float) $employee->experience_moe_years,
            experienceOutside: $employee->experience_outside_years === null ? null : (float) $employee->experience_outside_years,
            experienceCurrentTitle: $employee->current_title_since ? round($employee->current_title_since->diffInDays(today()) / 365.25, 1) : null,
            gradeLevel: $employee->grade_level,
            subjects: array_map('mb_strtolower', $employee->subjects ?? []),
            gradesTaught: array_map('strval', $employee->grades_taught ?? []),
            appraisalMin: $ratings->isEmpty() ? null : (float) $ratings->min(),
            appraisalAvg: $ratings->isEmpty() ? null : round((float) $ratings->avg(), 2),
            equivalentCompleted: $equivalent,
            hasLicence: $licences->isNotEmpty(),
            licenceLevel: $licences->max('level_no') ?? 0,
            pathLevel: (int) (EmployeePathProgress::where('employee_id', $employee->id)->whereHas('path', fn ($q) => $q->where('type', 'promotion'))->max('current_level_no') ?? 0),
            pdHours: app(AnnualHoursService::class)->allTime($employee),
        );
    }

    public function value(string $field): mixed
    {
        return match ($field) {
            'job_title' => $this->jobTitle,
            'job_category' => $this->jobCategory,
            'department' => $this->department,
            'school_type' => $this->schoolType,
            'school_stage' => $this->schoolStage,
            'education_stage' => $this->educationStage,
            'region' => $this->region,
            'experience_years' => $this->experienceYears,
            'qualification' => $this->qualification,
            'completed_program' => $this->completedPrograms,
            'skill_level' => $this->skills,
            'specialization' => $this->specialization,
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'school' => $this->schoolId,
            'age' => $this->age,
            'experience_moe_years' => $this->experienceMoe,
            'experience_outside_years' => $this->experienceOutside,
            'experience_current_title_years' => $this->experienceCurrentTitle,
            'grade_level' => $this->gradeLevel,
            'subject' => $this->subjects,
            'grade_taught' => $this->gradesTaught,
            'appraisal_min_rating' => $this->appraisalMin,
            'appraisal_avg_rating' => $this->appraisalAvg,
            'equivalent_completed' => $this->equivalentCompleted,
            'has_licence' => $this->hasLicence,
            'licence_level' => $this->licenceLevel,
            'path_level' => $this->pathLevel,
            'pd_hours' => $this->pdHours,
            default => null,
        };
    }
}
