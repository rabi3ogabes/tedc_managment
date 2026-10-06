<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title_ar', 'title_en', 'description_ar', 'description_en', 'starts_at', 'ends_at', 'audience', 'goal', 'reward', 'type', 'is_active', 'closed_at'])]
class Challenge extends Model
{
    use HasUuids;

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'audience' => 'array', 'goal' => 'array', 'reward' => 'array', 'is_active' => 'boolean', 'closed_at' => 'datetime'];

    public function participants(): HasMany
    {
        return $this->hasMany(ChallengeParticipant::class);
    }
}
