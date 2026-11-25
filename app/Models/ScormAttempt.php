<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['registration_id', 'lesson_id', 'package_id', 'attempt_no', 'cmi', 'completion_status', 'success_status', 'score_raw', 'score_min', 'score_max', 'score_scaled', 'total_time', 'suspend_data', 'location', 'started_at', 'committed_at'])]
class ScormAttempt extends Model
{
    use HasUuids;

    public function package(): BelongsTo
    {
        return $this->belongsTo(ContentPackage::class, 'package_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    protected $casts = ['cmi' => 'array', 'started_at' => 'datetime', 'committed_at' => 'datetime', 'score_raw' => 'float', 'score_scaled' => 'float'];
}
