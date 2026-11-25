<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'subject_type', 'subject_id', 'title_ar', 'title_en', 'description_ar', 'description_en', 'cover_path', 'visibility', 'join_policy', 'settings', 'created_by', 'archived_at', 'posts_count'])]
class Space extends Model
{
    use HasUuids;

    protected $casts = ['settings' => 'array', 'archived_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(SpaceMember::class, 'space_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'space_id');
    }
}
