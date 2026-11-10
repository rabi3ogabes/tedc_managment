<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['registration_id', 'skill_id', 'mastery', 'evidence_count'])]
class LearnerMastery extends Model
{
    use HasUuids;

    protected $table = 'learner_mastery';

    protected $casts = ['mastery' => 'float'];
}
