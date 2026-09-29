<?php

namespace App\Services\Eligibility;

use App\Models\Employee;
use App\Models\Registration;

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
    ) {}

    public static function fromEmployee(Employee $employee): self
    {
        $employee->loadMissing(['jobTitle', 'department', 'school', 'skills']);

        $completed = $employee->registrations()
            ->where('registrations.status', Registration::STATUS_COMPLETED)
            ->join('programs', 'programs.id', '=', 'registrations.program_id')
            ->pluck('programs.code')
            ->all();

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
            default => null,
        };
    }
}
