<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'title_en', 'title_ar', 'body_en', 'body_ar', 'audience', 'target_ids', 'attachments', 'cover_path', 'is_public', 'published_at', 'created_by', 'starts_at', 'ends_at', 'is_pinned', 'pin_order', 'status', 'media', 'event', 'audience_filter', 'notify_push', 'notify_email', 'export_to_ministry', 'exported_at', 'republished_from_id', 'archived_at'])]
class Announcement extends Model
{
    use Auditable, HasTranslations, HasUuids;

    public const AUDIENCES = ['all', 'schools', 'programs', 'roles', 'filter'];

    public const TYPES = ['news', 'announcement', 'circular', 'event', 'activity'];

    protected $casts = ['target_ids' => 'array', 'attachments' => 'array', 'is_public' => 'boolean', 'published_at' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'archived_at' => 'datetime', 'exported_at' => 'datetime', 'media' => 'array', 'event' => 'array', 'audience_filter' => 'array', 'is_pinned' => 'boolean', 'notify_push' => 'boolean', 'notify_email' => 'boolean', 'export_to_ministry' => 'boolean'];

    protected static function booted(): void
    {
        // Code that only sets published_at (seeders, imports) still means "published".
        static::creating(function (self $a) {
            if (! isset($a->attributes['status'])) {
                $a->status = $a->published_at ? 'published' : 'draft';
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(AnnouncementRsvp::class);
    }
}
