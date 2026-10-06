<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lesson_version', 'lesson_id', 'registration_id', 'employee_id', 'status', 'percent', 'last_position', 'furthest_position', 'watched_seconds', 'segments', 'sessions', 'best_score', 'attempts', 'first_opened_at', 'last_activity_at', 'completed_at'])]
class LessonProgress extends Model
{
    use HasUuids;

    protected $table = 'lesson_progress';

    protected $casts = ['segments' => 'array', 'percent' => 'float', 'last_position' => 'float', 'furthest_position' => 'float', 'best_score' => 'float', 'first_opened_at' => 'datetime', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'lesson_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
