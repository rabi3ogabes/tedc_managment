<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A rule that turns appraisals, observations, hires or specialisation into needs. */
#[Fillable(['name_ar', 'name_en', 'trigger', 'conditions', 'action', 'is_active', 'sort_order', 'last_run_at'])]
class NeedsRule extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['conditions' => 'array', 'action' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }
}
