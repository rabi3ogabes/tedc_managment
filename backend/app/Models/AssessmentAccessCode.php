<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The secret code that opens an in-centre exam (static or rotating). */
#[Fillable(['assessment_id', 'group_id', 'code_hash', 'secret', 'valid_from', 'valid_to', 'room_id', 'created_by'])]
class AssessmentAccessCode extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_to' => 'datetime'];
    }
}
