<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kind', 'title_ar', 'title_en', 'questions', 'version', 'approval_status', 'approval_note', 'approved_by', 'approved_at', 'is_default', 'is_system', 'settings', 'created_by', 'survey_id'])]
class EvaluationForm extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['questions' => 'array', 'settings' => 'array', 'approved_at' => 'datetime', 'is_default' => 'boolean', 'is_system' => 'boolean'];

    /** The engine survey that holds this form's questions since the migration (Gate 5); the form row is then a workflow pointer. */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class, 'survey_id');
    }
}
