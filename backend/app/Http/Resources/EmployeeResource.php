<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'user_id' => $this->user_id,
            'name' => $this->whenLoaded('user', fn () => $this->user->displayName()),
            'name_ar' => $this->whenLoaded('user', fn () => $this->user->name_ar),
            'name_en' => $this->whenLoaded('user', fn () => $this->user->name),
            'email' => $this->whenLoaded('user', fn () => $this->user->email),
            'phone' => $this->whenLoaded('user', fn () => $this->user->phone),
            'school' => $this->whenLoaded('school', fn () => $this->school ? ['id' => $this->school->id, 'name' => $this->school->translate('name'), 'region' => $this->school->region, 'type' => $this->school->type] : null),
            'school_id' => $this->school_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->translate('name')] : null),
            'department_id' => $this->department_id,
            'job_title' => $this->whenLoaded('jobTitle', fn () => $this->jobTitle ? ['id' => $this->jobTitle->id, 'code' => $this->jobTitle->code, 'name' => $this->jobTitle->translate('name'), 'category' => $this->jobTitle->category] : null),
            'job_title_id' => $this->job_title_id,
            'supervisor_id' => $this->supervisor_id,
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'birth_date' => $this->birth_date?->toDateString(),
            'age' => $this->age(),
            'hire_date' => $this->hire_date?->toDateString(),
            'experience_years' => $this->experience_years,
            'experience_moe_years' => $this->experience_moe_years, 'experience_outside_years' => $this->experience_outside_years, 'current_title_since' => $this->current_title_since?->toDateString(),
            'grade_level' => $this->grade_level, 'subjects' => $this->subjects ?? [], 'grades_taught' => $this->grades_taught ?? [],
            'education_stage' => $this->education_stage,
            'qualification' => $this->qualification,
            'specialization' => $this->specialization,
            'status' => $this->status,
            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn ($s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->translate('name'), 'category' => $s->category,
                'level' => (int) $s->pivot->level, 'source' => $s->pivot->source,
            ])),
        ];
    }
}
