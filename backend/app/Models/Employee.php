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

#[Fillable(['user_id', 'school_id', 'department_id', 'job_title_id', 'supervisor_id', 'employee_no', 'national_id', 'gender', 'nationality', 'hire_date', 'experience_years', 'education_stage', 'qualification', 'specialization', 'status'])]
#[Hidden(['national_id'])]
class Employee extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'hire_date' => 'date',
            'experience_years' => 'float',
        ];
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
