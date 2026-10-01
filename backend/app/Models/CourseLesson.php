<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['program_id', 'module_id', 'type', 'title_ar', 'title_en', 'description_ar', 'description_en', 'body_ar', 'body_en', 'sort_order', 'is_required', 'status', 'duration_seconds', 'source', 'file_path', 'file_name', 'file_mime', 'file_size', 'external_url', 'slide_count', 'settings'])]
class CourseLesson extends Model
{
    use HasTranslations, HasUuids;

    protected $casts = ['settings' => 'array', 'is_required' => 'boolean'];

    public const VIDEO = 'video';

    public const PRESENTATION = 'presentation';

    public const QUIZ = 'quiz';

    public const SURVEY = 'survey';

    public const ARTICLE = 'article';

    public const TYPES = [self::VIDEO, self::PRESENTATION, self::QUIZ, self::SURVEY, self::ARTICLE];

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class, 'lesson_id')->orderBy('sort_order');
    }

    public function surveyQuestions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class, 'lesson_id')->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'lesson_id');
    }

    /** A setting with its default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
