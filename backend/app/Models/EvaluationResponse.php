<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_id', 'answers', 'evidence', 'form_version', 'score', 'submitted_at'])]
class EvaluationResponse extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['answers' => 'array', 'evidence' => 'array', 'score' => 'float', 'submitted_at' => 'datetime'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EvaluationAssignment::class, 'assignment_id');
    }
}
