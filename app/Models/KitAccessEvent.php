<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a trainer did with a training kit in the protected reader or presenter (and every attempt to capture it). */
#[Fillable(['user_id', 'kit_id', 'kit_file_id', 'program_id', 'session_id', 'kind', 'meta', 'ip', 'user_agent', 'created_at'])]
class KitAccessEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['acknowledged', 'opened', 'presented', 'capture_attempt', 'hidden', 'print_attempt', 'blocked', 'tamper'];

    /** Kinds that mean somebody tried to take the content out: the centre is told. */
    public const ALERTS = ['capture_attempt', 'print_attempt', 'tamper'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(KitFile::class, 'kit_file_id');
    }
}
