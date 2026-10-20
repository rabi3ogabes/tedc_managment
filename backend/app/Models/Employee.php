<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['national_id_hash', 'education_level', 'certificate_name', 'job_class', 'employment_class', 'employee_kind', 'employee_type_code', 'employment_seq', 'sub_unit', 'source_system', 'imported_at', 'user_id', 'school_id', 'department_id', 'job_title_id', 'supervisor_id', 'employee_no', 'national_id', 'gender', 'nationality', 'birth_date', 'hire_date', 'experience_years', 'education_stage', 'qualification', 'specialization', 'status', 'experience_moe_years', 'experience_outside_years', 'current_title_since', 'grade_level', 'subjects', 'grades_taught'])]
#[Hidden(['national_id', 'national_id_hash'])]
class Employee extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'imported_at' => 'datetime', 'hire_date' => 'date', 'current_title_since' => 'date', 'subjects' => 'array', 'grades_taught' => 'array',
            'birth_date' => 'date',
            'experience_years' => 'float',
        ];
    }

    /** The blind index of a national ID: the ID itself is encrypted (so it cannot be searched), this keyed hash can. */
    public static function nationalIdHash(string $id): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $id) ?? $id, (string) config('app.key'));
    }

    /** Age in whole years, or null when the birth date is unknown. */
    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /** Every employee number the person has had (the current one is also on the employee). */
    public function numbers(): HasMany
    {
        return $this->hasMany(EmployeeNumber::class)->orderByDesc('is_current')->orderByDesc('appointed_on');
    }

    public function pdActivities(): HasMany
    {
        return $this->hasMany(PdActivity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'employee_skills')
            ->using(EmployeeSkill::class)
            ->withPivot(['id', 'level', 'source', 'program_id', 'verified_at'])
            ->withTimestamps();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function completedProgramIds(): array
    {
        return $this->registrations()->where('status', Registration::STATUS_COMPLETED)->pluck('program_id')->all();
    }
}
