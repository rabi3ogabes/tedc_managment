<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'group_id', 'period', 'metrics', 'qualitative', 'classification', 'classification_reasons', 'recommendations_ar', 'recommendations_en', 'status', 'prepared_by', 'approved_by', 'approved_at'])]
class ProgramEvaluationReport extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['metrics' => 'array', 'qualitative' => 'array', 'classification_reasons' => 'array', 'approved_at' => 'datetime'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
