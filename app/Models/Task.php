<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['program_id', 'program_session_id', 'title_en', 'title_ar', 'instructions_en', 'instructions_ar', 'due_at', 'submission_types', 'max_file_mb', 'is_required', 'self_assessed', 'created_by'])]
class Task extends Model
{
    use HasTranslations, HasUuids;

    public const TYPES = ['pdf', 'word', 'image', 'text'];

    protected $casts = ['due_at' => 'datetime', 'submission_types' => 'array', 'is_required' => 'boolean', 'self_assessed' => 'boolean'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }
}
