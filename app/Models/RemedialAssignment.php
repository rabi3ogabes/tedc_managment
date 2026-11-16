<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A review lesson given to one learner because their mastery of its competency is low; cleared once it is mastered. */
#[Fillable(['registration_id', 'lesson_id', 'skill_id', 'mastery', 'assigned_at', 'cleared_at', 'cleared_mastery'])]
class RemedialAssignment extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['mastery' => 'float', 'cleared_mastery' => 'float', 'assigned_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'lesson_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
