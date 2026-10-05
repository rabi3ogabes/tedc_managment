<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Required level of a competency for a job (optionally per stage and subject). */
#[Fillable(['job_title_id', 'skill_id', 'education_stage', 'subject', 'required_level', 'weight'])]
class JobCompetencyRequirement extends Model
{
    use HasUuids;
}
