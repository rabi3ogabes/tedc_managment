<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['program_id', 'skill_id', 'action', 'module_id', 'lesson_id', 'skip_at', 'remedial_below', 'is_active'])]
class AdaptiveRule extends Model
{
    use HasUuids;

    protected $table = 'adaptive_rules';

    protected $casts = ['is_active' => 'boolean', 'skip_at' => 'float', 'remedial_below' => 'float'];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
