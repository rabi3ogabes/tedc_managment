<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['space_id', 'title', 'starts_at', 'ends_at', 'location', 'online_url', 'agenda', 'rsvp_required', 'reminded_at', 'created_by'])]
class SpaceEvent extends Model
{
    use HasUuids;

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminded_at' => 'datetime', 'rsvp_required' => 'boolean'];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(SpaceEventRsvp::class, 'event_id');
    }
}
