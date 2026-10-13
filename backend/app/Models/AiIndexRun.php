<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One run of re-embedding the retrieval index with an embedder: progress, outcome, and who started it. */
#[Fillable(['model', 'dim', 'status', 'total', 'done', 'error', 'started_by', 'started_at', 'finished_at'])]
class AiIndexRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['dim' => 'integer', 'total' => 'integer', 'done' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
