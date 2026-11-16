<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file shared in a community post or comment: pending until a post uses it, then attached. Stored privately. */
#[Fillable(['user_id', 'space_id', 'path', 'thumb_path', 'name', 'mime', 'size', 'width', 'height', 'status', 'attached_type', 'attached_id'])]
class SocialUpload extends Model
{
    use HasUuids;

    protected $casts = ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
