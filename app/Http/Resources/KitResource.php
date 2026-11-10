<?php

namespace App\Http\Resources;

use App\Models\TrainingKit;
use App\Services\FileStorage;
use App\Services\Kits\KitAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrainingKit */
class KitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user ? KitAccess::memberRole($user, $this->resource) : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->translate('title'),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'description' => $this->translate('description'),
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'status' => $this->status,
            'delivery' => $this->delivery ?? 'in_person',
            'audience' => $this->audience,
            'duration_hours' => $this->duration_hours,
            'objectives' => $this->objectives ?? [],
            'tags' => $this->tags ?? [],
            'version' => $this->version,
            'review_round' => $this->review_round,
            'cover_url' => FileStorage::publicUrl($this->cover_path),
            'program' => $this->whenLoaded('program', fn () => $this->program ? ['id' => $this->program->id, 'code' => $this->program->code, 'title' => $this->program->translate('title')] : null),
            'program_id' => $this->program_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->translate('name'), 'color' => $this->category->color] : null),
            'category_id' => $this->category_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->displayName()] : null),
            'owner_id' => $this->owner_id,
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'user_id' => $m->user_id, 'name' => $m->user?->displayName(), 'email' => $m->user?->email, 'role' => $m->role,
            ])->values()),
            'due_at' => $this->due_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'files_count' => $this->whenCounted('files'),
            'open_comments' => $this->when(isset($this->open_comments), fn () => (int) $this->open_comments),
            'blocking_comments' => $this->when(isset($this->blocking_comments), fn () => (int) $this->blocking_comments),
            'progress' => $this->when(isset($this->progress), fn () => $this->progress),
            'my_role' => $role,
            'can' => $user ? [
                'manage' => KitAccess::canManage($user, $this->resource),
                'edit' => KitAccess::canEditContent($user, $this->resource),
                'review' => KitAccess::canReview($user, $this->resource),
                'comment' => KitAccess::canComment($user, $this->resource),
                'generate' => KitAccess::canGenerate($user, $this->resource),
                'publish' => KitAccess::canPublish($user),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
