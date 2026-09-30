<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['kit_id', 'name', 'original_name', 'kind', 'category', 'source', 'mime', 'size', 'storage_path', 'content', 'revision', 'version', 'notes', 'sort_order', 'uploaded_by', 'updated_by'])]
class KitFile extends Model
{
    use HasUuids, SoftDeletes;

    public const KINDS = ['presentation', 'document', 'pdf', 'image', 'video', 'other'];

    public const CATEGORIES = ['presentation', 'trainer_guide', 'handout', 'assessment', 'activity', 'media', 'other'];

    protected $casts = ['content' => 'array', 'size' => 'integer', 'revision' => 'integer', 'version' => 'integer'];

    /** Maps a file extension / mime to the kind the studio knows how to open. */
    public static function kindFor(string $extension, ?string $mime = null): string
    {
        $extension = strtolower($extension);

        return match (true) {
            in_array($extension, ['pptx', 'ppt', 'potx', 'ppsx'], true) => 'presentation',
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['docx', 'doc', 'odt', 'rtf', 'txt', 'md'], true) => 'document',
            in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true) || str_starts_with((string) $mime, 'image/') => 'image',
            in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true) || str_starts_with((string) $mime, 'video/') => 'video',
            default => 'other',
        };
    }

    public static function defaultCategory(string $kind): string
    {
        return match ($kind) {
            'presentation' => 'presentation', 'image', 'video' => 'media', 'document', 'pdf' => 'handout', default => 'other',
        };
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KitFileVersion::class, 'file_id')->orderByDesc('version');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(KitComment::class, 'file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDeck(): bool
    {
        return $this->kind === 'presentation' && $this->content !== null;
    }
}
