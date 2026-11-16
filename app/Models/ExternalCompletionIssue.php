<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A completion a provider reported that TEDC could not use (the person is unknown, or not registered in the programme), kept until the cause is gone. */
#[Fillable(['provider', 'external_course_id', 'course_title', 'learner_ref', 'reason', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolution', 'resolved_by'])]
class ExternalCompletionIssue extends Model
{
    use HasUuids;

    public const UNKNOWN_EMPLOYEE = 'unknown_employee';

    public const NOT_REGISTERED = 'not_registered';

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'occurrences' => 'integer'];
}
