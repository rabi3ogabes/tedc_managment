<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['definition_id', 'params', 'frequency', 'formats', 'recipients', 'lang', 'last_run_at', 'next_run_at', 'is_active', 'last_error', 'created_by'])]
class ReportSchedule extends Model
{
    use HasUuids;

    protected $casts = ['params' => 'array', 'formats' => 'array', 'recipients' => 'array', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime', 'is_active' => 'boolean'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'definition_id');
    }
}
