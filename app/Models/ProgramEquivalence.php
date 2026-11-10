<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A program counted as the same as another when checking repeats. */
#[Fillable(['program_id', 'equivalent_program_id', 'bidirectional', 'note'])]
class ProgramEquivalence extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['bidirectional' => 'boolean'];
    }

    public function equivalent(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'equivalent_program_id');
    }
}
