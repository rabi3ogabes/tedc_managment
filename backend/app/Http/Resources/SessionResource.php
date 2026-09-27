<?php

namespace App\Http\Resources;

use App\Models\ProgramSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProgramSession */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'code' => $this->program->code,
                'title' => $this->program->translate('title'),
                'capacity' => $this->program->capacity,
                'status' => $this->program->status,
            ]),
            'sequence' => $this->sequence,
            'title' => $this->translate('title'),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'description' => $this->description,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'duration_minutes' => $this->durationMinutes(),
            'location' => $this->location_text ?? $this->room?->translate('name'),
            'location_text' => $this->location_text,
            'online_url' => $this->online_url,
            'activities' => $this->activities ?? [],
            'status' => $this->status,
            'trainer_id' => $this->trainer_id,
            'trainer' => $this->whenLoaded('trainer', fn () => $this->trainer ? ['id' => $this->trainer->id, 'name' => $this->trainer->translate('name')] : null),
            'training_room_id' => $this->training_room_id,
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'name' => $this->room->translate('name'), 'building' => $this->room->building] : null),
            'attendance_count' => $this->whenCounted('attendance'),
        ];
    }
}
