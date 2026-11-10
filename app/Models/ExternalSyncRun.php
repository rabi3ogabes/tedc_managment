<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One read of a learning provider: what it found, what it did, and why it stopped when it did. */
#[Fillable(['provider', 'status', 'trigger', 'triggered_by', 'started_at', 'finished_at', 'catalogue_at', 'catalogue_full', 'completions_at', 'courses_seen', 'courses_added', 'completions_seen', 'completions_applied', 'issues_found', 'error'])]
class ExternalSyncRun extends Model
{
    use HasUuids;

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'catalogue_at' => 'datetime', 'completions_at' => 'datetime', 'catalogue_full' => 'boolean'];
}
