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
            'group' => $this->whenLoaded('trainingGroup', fn () => $this->trainingGroup ? [
                'id' => $this->trainingGroup->id, 'code' => $this->trainingGroup->code, 'title' => $this->trainingGroup->displayTitle(), 'status' => $this->trainingGroup->status,
                'status_reason' => $this->trainingGroup->status_reason, 'postponed_to' => $this->trainingGroup->postponed_to?->toDateString(), 'start_date' => $this->trainingGroup->start_date?->toDateString(),
            ] : null),
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
            'pass_status' => $this->pass_status,
            'passed_via' => $this->passed_via,
            'weighted_score' => $this->weighted_score,
            'participation_percent' => $this->participation_percent,
            'certificates' => $this->whenLoaded('certificates', fn () => CertificateResource::collection($this->certificates)),
            'impact_score' => $this->impact_score,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'certificate' => $this->whenLoaded('certificate', fn () => $this->certificate ? new CertificateResource($this->certificate) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
