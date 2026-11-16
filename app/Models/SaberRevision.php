<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A programme's planned state in SABER+, waiting to be reviewed and published (docs/saber/05-review-and-flexible-import.md). */
#[Fillable(['program_id', 'status', 'source', 'state', 'published_state', 'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note'])]
class SaberRevision extends Model
{
    use Auditable, HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const PUBLISHED = 'published';

    public const DISCARDED = 'discarded';

    public const OPEN = [self::DRAFT, self::SUBMITTED];

    protected $casts = ['state' => 'array', 'published_state' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
