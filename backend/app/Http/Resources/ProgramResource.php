<?php

namespace App\Http\Resources;

use App\Models\Program;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Program */
class ProgramResource extends JsonResource
{
    /** Fields only the detail and edit screens need; dropped from catalogue lists to keep them light. */
    private const DETAIL_ONLY = ['title_ar', 'title_en', 'summary_ar', 'summary_en', 'description', 'description_ar', 'description_en', 'objectives'];

    /** Resource collection for listings (catalogue cards, home page). */
    public static function compactCollection($resource): AnonymousResourceCollection
    {
        $collection = static::collection($resource);
        $collection->collection->each(fn (self $item) => $item->compact = true);

        return $collection;
    }

    public bool $compact = false;

    public function toArray(Request $request): array
    {
        $data = $this->payload($request);

        return $this->compact ? array_diff_key($data, array_flip(self::DETAIL_ONLY)) : $data;
    }

    private function payload(Request $request): array
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
            'remote' => $this->remote,
            'certificate_template_id' => $this->certificate_template_id,
            'trainer_certificate_template_id' => $this->trainer_certificate_template_id,
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
            'source_type' => $this->source_type,
            'audience' => $this->audience,
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
