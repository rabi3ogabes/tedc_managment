<?php

namespace App\Http\Resources;

use App\Models\Program;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Program */
class ProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $seatsTaken = $this->seats_taken ?? null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->translate('title'),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'summary' => $this->translate('summary'),
            'summary_ar' => $this->summary_ar,
            'summary_en' => $this->summary_en,
            'description' => $this->translate('description'),
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'objectives' => $this->objectives ?? [],
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->translate('name'),
                'color' => $this->category->color,
                'icon' => $this->category->icon,
            ] : null),
            'category_id' => $this->category_id,
            'delivery_mode' => $this->delivery_mode,
            'level' => $this->level,
            'total_hours' => $this->total_hours,
            'capacity' => $this->capacity,
            'seats_taken' => $seatsTaken,
            'seats_available' => $seatsTaken !== null ? max(0, $this->capacity - $seatsTaken) : null,
            'min_attendance_percent' => $this->min_attendance_percent,
            'requires_tasks' => $this->requires_tasks,
            'requires_evaluation' => $this->requires_evaluation,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'registration_opens_at' => $this->registration_opens_at?->toIso8601String(),
            'registration_closes_at' => $this->registration_closes_at?->toIso8601String(),
            'registration_open' => $this->isRegistrationOpen(),
            'registration_modes' => $this->registration_modes ?? [],
            'status' => $this->status,
            'is_featured' => $this->is_featured,
            'cover_url' => FileStorage::publicUrl($this->cover_path),
            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn ($s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->translate('name'), 'target_level' => $s->pivot->target_level,
            ])),
            'trainers' => TrainerResource::collection($this->whenLoaded('trainers')),
            'sessions' => SessionResource::collection($this->whenLoaded('sessions')),
            'target_groups' => $this->whenLoaded('targetGroups', fn () => $this->targetGroups->map(fn ($g) => [
                'id' => $g->id,
                'job_title_id' => $g->job_title_id,
                'job_title' => $g->jobTitle?->translate('name'),
                'department_id' => $g->department_id,
                'school_type' => $g->school_type,
                'education_stage' => $g->education_stage,
                'description' => $g->description,
            ])),
            'eligibility_rules' => $this->whenLoaded('eligibilityRules'),
            'registrations_count' => $this->whenCounted('registrations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
