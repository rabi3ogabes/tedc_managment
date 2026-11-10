<?php

namespace App\Http\Resources;

use App\Models\KitComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KitComment */
class KitCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'kit_id' => $this->kit_id,
            'file_id' => $this->file_id,
            'file' => $this->whenLoaded('file', fn () => $this->file ? ['id' => $this->file->id, 'name' => $this->file->name, 'kind' => $this->file->kind] : null),
            'parent_id' => $this->parent_id,
            'author' => $this->whenLoaded('author', fn () => ['id' => $this->author->id, 'name' => $this->author->displayName(), 'roles' => $this->author->roles->pluck('slug')->all()]),
            'author_id' => $this->author_id,
            'body' => $this->body,
            'anchor' => $this->anchor,
            'file_version' => $this->file_version,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->displayName()] : null),
            'assignee_id' => $this->assignee_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'review_round' => $this->review_round,
            'mentions' => $this->mentions ?? [],
            'replies' => static::collection($this->whenLoaded('replies')),
            'mine' => $user && $user->id === $this->author_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
