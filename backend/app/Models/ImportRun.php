<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An import started in the browser: files, steps, progress and report (see App\Http\Controllers\Api\V1\Admin\ImportRunController). */
#[Fillable(['kind', 'status', 'files', 'plan', 'stats', 'issues', 'report', 'error', 'created_by', 'started_at', 'finished_at'])]
class ImportRun extends Model
{
    use HasUuids;

    protected $casts = ['files' => 'array', 'plan' => 'array', 'stats' => 'array', 'issues' => 'array', 'report' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
