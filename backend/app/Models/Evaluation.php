<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'registration_id', 'employee_id', 'ratings', 'satisfaction_score', 'pre_test_score', 'post_test_score', 'comments', 'allow_testimonial', 'submitted_at'])]
class Evaluation extends Model
{
    use HasUuids;

    protected $casts = [
        'ratings' => 'array', 'satisfaction_score' => 'float', 'pre_test_score' => 'float',
        'post_test_score' => 'float', 'allow_testimonial' => 'boolean', 'submitted_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
