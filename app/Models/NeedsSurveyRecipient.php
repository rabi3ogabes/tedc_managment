<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['survey_id', 'user_id', 'employee_id', 'notified_at', 'reminded_at', 'responded_at'])]
class NeedsSurveyRecipient extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'reminded_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NeedsSurvey::class, 'survey_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
