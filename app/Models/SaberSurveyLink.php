<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the uploaded survey-links workbook, optionally given to a programme. */
#[Fillable(['serial', 'survey_link', 'survey_edit_link', 'program_id'])]
class SaberSurveyLink extends Model
{
    use HasUuids;

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
