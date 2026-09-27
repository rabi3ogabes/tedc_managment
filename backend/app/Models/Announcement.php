<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'title_en', 'title_ar', 'body_en', 'body_ar', 'audience', 'target_ids', 'attachments', 'cover_path', 'is_public', 'published_at', 'created_by'])]
class Announcement extends Model
{
    use Auditable, HasTranslations, HasUuids;

    public const AUDIENCES = ['all', 'schools', 'programs', 'roles'];

    protected $casts = ['target_ids' => 'array', 'attachments' => 'array', 'is_public' => 'boolean', 'published_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
