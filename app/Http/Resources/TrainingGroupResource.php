<?php

namespace App\Http\Resources;

use App\Models\TrainingGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrainingGroup */
class TrainingGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $taken = $this->seatsTaken();

        return [
            'id' => $this->id, 'code' => $this->code, 'sequence' => $this->sequence, 'title' => $this->displayTitle(), 'title_ar' => $this->title_ar, 'title_en' => $this->title_en,
            'program' => $this->whenLoaded('program', fn () => ['id' => $this->program->id, 'code' => $this->program->code, 'title' => $this->program->translate('title'), 'kind' => $this->program->kind, 'category_id' => $this->program->category_id]),
            'delivery_mode' => $this->delivery_mode, 'start_date' => $this->start_date?->toDateString(), 'end_date' => $this->end_date?->toDateString(),
            'registration_opens_at' => $this->registration_opens_at?->toIso8601String(), 'registration_closes_at' => $this->registration_closes_at?->toIso8601String(),
            'capacity' => $this->capacity, 'seats_taken' => $taken, 'seats_available' => max(0, $this->capacity - $taken), 'min_attendance_percent' => $this->min_attendance_percent,
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? ['id' => $this->supervisor->id, 'name' => $this->supervisor->displayName()] : null),
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'name' => $this->room->translate('name'), 'code' => $this->room->code] : null),
            'status' => $this->status, 'status_reason' => $this->status_reason, 'postponed_to' => $this->postponed_to?->toDateString(), 'allowed_transitions' => TrainingGroup::TRANSITIONS[$this->status] ?? [],
            'is_emergency' => $this->is_emergency, 'published' => $this->published_at !== null, 'plan_item_id' => $this->plan_item_id,
            'sessions_count' => $this->whenCounted('sessions'), 'trainers' => $this->whenLoaded('trainers', fn () => $this->trainers->map(fn ($t) => ['id' => $t->id, 'trainer_id' => $t->trainer_id, 'name' => $t->trainer?->translate('name'), 'role' => $t->role, 'status' => $t->status])->values()),
        ];
    }
}
