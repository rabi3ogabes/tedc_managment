<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An incoming need or proposal that may become one or more workshops. A راصد need is entered by hand with
 * its reference number: there is no electronic integration with راصد in this system.
 */
#[Fillable(['source', 'reference_no', 'title', 'description', 'domain', 'target_audience', 'received_at', 'review_status', 'source_url', 'notes', 'school_ids',
    'entity_name', 'proposer_name', 'justification', 'expected_outcomes', 'attachments', 'created_by', 'reviewed_by', 'reviewed_at'])]
class PlcNeed extends Model
{
    use HasUuids;

    public const SOURCES = ['rased', 'centre', 'entity', 'school'];

    public const REVIEW_STATUSES = ['new', 'under_review', 'converted', 'declined'];

    protected function casts(): array
    {
        return ['school_ids' => 'array', 'attachments' => 'array', 'received_at' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workshops(): HasMany
    {
        return $this->hasMany(PlcWorkshop::class, 'need_id');
    }
}
