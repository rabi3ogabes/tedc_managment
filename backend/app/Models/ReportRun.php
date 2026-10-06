<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['definition_id', 'params', 'formats', 'status', 'rows_count', 'file_paths', 'personal', 'requested_by', 'schedule_id', 'lang', 'started_at', 'finished_at', 'expires_at', 'error'])]
class ReportRun extends Model
{
    use HasUuids;

    protected $casts = ['params' => 'array', 'formats' => 'array', 'file_paths' => 'array', 'personal' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'definition_id');
    }
}
