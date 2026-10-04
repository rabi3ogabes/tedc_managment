<?php

namespace App\Models;

use App\Services\FileStorage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An image used inside a kit deck (uploaded, imported from a PPTX, or AI generated). */
#[Fillable(['kit_id', 'file_id', 'name', 'mime', 'size', 'storage_path', 'prompt', 'source', 'width', 'height', 'meta', 'created_by'])]
class KitAsset extends Model
{
    use HasUuids;

    protected $casts = ['meta' => 'array'];

    /** image | video | audio, from the file type. */
    public function kind(): string
    {
        return str_starts_with((string) $this->mime, 'video/') ? 'video' : (str_starts_with((string) $this->mime, 'audio/') ? 'audio' : 'image');
    }

    /** Pictures live in the documents bucket; big videos and sounds go to the materials bucket (larger files allowed). */
    public function bucket(): string
    {
        return $this->meta['bucket'] ?? 'documents';
    }

    /** The row the studio shows for an asset: what it is and a temporary address to view or play it. */
    public function present(FileStorage $storage): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'kind' => $this->kind(), 'size' => $this->size, 'width' => $this->width, 'height' => $this->height,
            'duration' => $this->meta['duration'] ?? null, 'prompt' => $this->prompt, 'source' => $this->source, 'provider' => $this->meta['provider'] ?? null,
            'url' => $storage->temporaryUrl($this->bucket(), $this->storage_path, (int) config('tedc.kits.asset_url_ttl')),
        ];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }
}
