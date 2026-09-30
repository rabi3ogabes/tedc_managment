<?php

namespace App\Http\Resources;

use App\Models\KitFile;
use App\Services\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KitFile */
class KitFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $storage = app(FileStorage::class);

        return [
            'id' => $this->id,
            'kit_id' => $this->kit_id,
            'name' => $this->name,
            'original_name' => $this->original_name,
            'kind' => $this->kind,
            'category' => $this->category,
            'source' => $this->source,
            'mime' => $this->mime,
            'size' => $this->size,
            'version' => $this->version,
            'revision' => $this->revision,
            'is_deck' => $this->content !== null,
            'slides_count' => $this->content !== null ? count($this->content['slides'] ?? []) : null,
            'notes' => $this->notes,
            'has_binary' => $this->storage_path !== null,
            // Short-lived link for viewers (PDF, Word, images, video); the API decides who receives it.
            'url' => $this->when($this->storage_path && $this->kind !== 'presentation' || ($this->storage_path && $request->boolean('with_url')), fn () => $storage->temporaryUrl('documents', $this->storage_path, 3600)),
            'open_comments' => $this->when(isset($this->open_comments), fn () => (int) $this->open_comments),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->displayName()),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->displayName()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
