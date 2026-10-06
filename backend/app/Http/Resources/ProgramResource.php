<?php

namespace App\Http\Resources;

use App\Models\Program;
use App\Payments\CatalogPricing;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
            'pricing' => app(CatalogPricing::class)->forProgram($this->resource, $request->user('api'), ! $this->compact),
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->translate('title'),
            'coordinator_id' => $this->coordinator_id,
            'coordinator' => $this->whenLoaded('coordinator', fn () => $this->coordinator ? ['id' => $this->coordinator->id, 'name' => $this->coordinator->displayName()] : null),
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
            'external_platform' => $this->external_platform ? Arr::only($this->external_platform, ['name', 'provider']) : null,
            'level' => $this->level,
            'remote' => $this->remote,
            'has_course' => $this->has_course,
            'course_sequential' => $this->course_sequential,
            'course_auto_certificate' => $this->course_auto_certificate,
            'course_completion_percent' => $this->course_completion_percent,
            'certificate_template_id' => $this->certificate_template_id,
            'trainer_certificate_template_id' => $this->trainer_certificate_template_id,
            'total_hours' => $this->total_hours,
            'capacity' => $this->capacity,
            'seats_taken' => $seatsTaken,
            'seats_available' => $seatsTaken !== null ? max(0, $this->capacity - $seatsTaken) : null,
            'min_attendance_percent' => $this->min_attendance_percent,
            'require_biometric' => (bool) $this->require_biometric,
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
            'kind' => $this->kind, 'parent_id' => $this->parent_id, 'axes' => $this->axes ?? [], 'is_emergency' => (bool) $this->is_emergency, 'emergency_reason' => $this->emergency_reason,
            'owner_type' => $this->owner_type, 'owner_school_id' => $this->owner_school_id, 'approval_status' => $this->approval_status,
            'groups_count' => $this->whenCounted('groups'), 'open_groups' => $this->whenCounted('open_groups'),
            'groups' => $this->whenLoaded('groups', fn () => TrainingGroupResource::collection($this->groups)),
            'units' => $this->whenLoaded('units', fn () => $this->units->map(fn ($u) => ['id' => $u->id, 'title' => $u->translate('title'), 'title_ar' => $u->title_ar, 'title_en' => $u->title_en, 'hours' => $u->hours, 'objectives' => $u->objectives ?? [], 'summary' => $u->translate('summary'), 'skills' => $u->skills->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->translate('name')])->values()])),
            'children' => $this->whenLoaded('children', fn () => $this->children->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'title' => $c->translate('title'), 'total_hours' => $c->total_hours, 'status' => $c->status])),
            'registrations_count' => $this->whenCounted('registrations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
