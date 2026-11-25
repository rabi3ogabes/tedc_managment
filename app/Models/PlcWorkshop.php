<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The PLC side of a workshop. The workshop itself is a {@see Program} (sessions, registrations, trainers,
 * attendance and evaluations all stay on the programme engine); this row adds the source, the scenario,
 * the host school, the coordinator and the workflow state.
 */
#[Fillable(['program_id', 'community_id', 'need_id', 'source', 'source_ref', 'organiser', 'coordinator_user_id', 'priority', 'expected_participants', 'prerequisites',
    'outcomes', 'target_audience', 'domain', 'scenario', 'host_school_id', 'workflow_state', 'state_changed_at', 'approval_path', 'decision_note', 'attachments'])]
class PlcWorkshop extends Model
{
    protected $primaryKey = 'program_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const SOURCES = ['rased', 'centre', 'entity', 'school'];

    public const SCENARIOS = ['shared', 'internal'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected function casts(): array
    {
        return ['approval_path' => 'array', 'attachments' => 'array', 'state_changed_at' => 'datetime', 'expected_participants' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(PlcCommunity::class, 'community_id');
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(PlcNeed::class, 'need_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_user_id');
    }

    public function hostSchool(): BelongsTo
    {
        return $this->belongsTo(School::class, 'host_school_id');
    }

    public function schools(): HasMany
    {
        return $this->hasMany(PlcWorkshopSchool::class, 'program_id', 'program_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PlcDecision::class, 'subject_id', 'program_id')->where('subject_type', self::class)->orderByDesc('created_at');
    }

    public function isInternal(): bool
    {
        return $this->scenario === 'internal';
    }
}
