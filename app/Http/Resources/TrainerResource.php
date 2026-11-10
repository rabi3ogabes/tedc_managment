<?php

namespace App\Http\Resources;

use App\Models\Trainer;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Trainer */
class TrainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manage = (bool) $request->user()?->hasPermission('trainers.manage');

        return [
            'id' => $this->id,
            'name' => $this->translate('name'),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'title' => $this->translate('title'),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'bio' => $this->translate('bio'),
            'bio_ar' => $this->bio_ar,
            'bio_en' => $this->bio_en,
            'specializations' => $this->specializations ?? [],
            'photo_url' => FileStorage::publicUrl($this->photo_path),
            'source' => $this->source,
            'source_label' => $this->sourceLabel(),
            'is_external' => $this->is_external,
            'organization' => $this->partner ? $this->partner->translate('name') : $this->organization,
            'country' => $this->country,
            'languages' => $this->languages ?? [],
            'experience_years' => $this->experience_years,
            'rating' => $this->rating,
            'status' => $this->status,
            'role' => $this->whenPivotLoaded('program_trainer', fn () => $this->pivot->role),
            'programs_count' => $this->whenCounted('programs'),
            'sessions_count' => $this->whenCounted('sessions'),
            // Internal details are only for people who manage trainers.
            'email' => $this->when($manage, $this->email),
            'phone' => $this->when($manage, $this->phone),
            'user_id' => $this->when($manage, $this->user_id),
            'employee_id' => $this->when($manage, $this->employee_id),
            'school_id' => $this->when($manage, $this->school_id),
            'school' => $this->when($manage, fn () => $this->school ? ['id' => $this->school->id, 'name' => $this->school->translate('name')] : null),
            'partner_id' => $this->when($manage, $this->partner_id),
            'partner' => $this->when($manage, fn () => $this->partner ? ['id' => $this->partner->id, 'name' => $this->partner->translate('name'), 'type' => $this->partner->type] : null),
            'city' => $this->when($manage, $this->city),
            'hourly_rate' => $this->when($manage, $this->hourly_rate),
            'currency' => $this->when($manage, $this->currency),
            'notes' => $this->when($manage, $this->notes),
        ];
    }
}
