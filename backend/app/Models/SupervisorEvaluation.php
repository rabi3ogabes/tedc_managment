<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['registration_id', 'program_id', 'employee_id', 'supervisor_user_id', 'application_score', 'behavior_change', 'comments', 'recommendations', 'submitted_at'])]
class SupervisorEvaluation extends Model
{
    use HasUuids;

    protected $casts = ['submitted_at' => 'datetime', 'application_score' => 'integer'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
