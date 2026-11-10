<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A review comment pinned to a slide, element, PDF page, text passage or video moment. */
#[Fillable(['kit_id', 'file_id', 'parent_id', 'author_id', 'body', 'anchor', 'file_version', 'category', 'severity', 'status', 'assignee_id', 'resolved_by', 'resolved_at', 'review_round', 'mentions'])]
class KitComment extends Model
{
    use HasUuids;

    public const CATEGORIES = ['content', 'design', 'language', 'accuracy', 'alignment', 'accessibility', 'other'];

    public const SEVERITIES = ['info', 'minor', 'major', 'critical'];

    public const STATUSES = ['open', 'addressed', 'resolved'];

    /** Severities that block approval while unresolved. */
    public const BLOCKING = ['major', 'critical'];

    protected $casts = ['anchor' => 'array', 'mentions' => 'array', 'resolved_at' => 'datetime'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(KitFile::class, 'file_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(KitComment::class, 'parent_id')->orderBy('created_at');
    }
}
