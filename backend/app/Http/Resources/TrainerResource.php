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
            'is_external' => $this->is_external,
            'organization' => $this->organization,
            'rating' => $this->rating,
            'email' => $this->when($request->user()?->hasPermission('trainers.manage'), $this->email),
            'phone' => $this->when($request->user()?->hasPermission('trainers.manage'), $this->phone),
            'user_id' => $this->when($request->user()?->hasPermission('trainers.manage'), $this->user_id),
            'status' => $this->status,
            'role' => $this->whenPivotLoaded('program_trainer', fn () => $this->pivot->role),
            'programs_count' => $this->whenCounted('programs'),
        ];
    }
}
