<?php

namespace App\Http\Resources;

use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Registration */
class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'program' => new ProgramResource($this->whenLoaded('program')),
            'employee_id' => $this->employee_id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'source' => $this->source,
            'status' => $this->status,
            'eligibility' => $this->eligibility_snapshot,
            'attendance_percent' => $this->attendance_percent,
            'tasks_completed' => $this->tasks_completed,
            'course_percent' => $this->course_percent,
            'course_completed' => $this->course_completed,
            'has_course' => (bool) $this->program?->has_course,
            'evaluation_completed' => $this->evaluation_completed,
            'certificate_status' => $this->certificate_status,
            'impact_score' => $this->impact_score,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'certificate' => $this->whenLoaded('certificate', fn () => $this->certificate ? new CertificateResource($this->certificate) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
