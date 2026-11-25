<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A professional learning community: the organisational frame that gathers schools around a domain and its workshops. */
#[Fillable(['code', 'title_ar', 'title_en', 'description_ar', 'description_en', 'domain', 'status', 'lead_user_id', 'host_school_id', 'space_id', 'settings', 'created_by'])]
class PlcCommunity extends Model
{
    use HasUuids;

    public const STATUSES = ['active', 'archived'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function hostSchool(): BelongsTo
    {
        return $this->belongsTo(School::class, 'host_school_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'plc_community_schools', 'community_id', 'school_id')->withPivot(['id', 'role', 'joined_at'])->withTimestamps();
    }

    public function workshops(): HasMany
    {
        return $this->hasMany(PlcWorkshop::class, 'community_id');
    }

    public function title(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'en' && $this->title_en) ? $this->title_en : $this->title_ar;
    }
}
