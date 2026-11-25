<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Group of competencies (e.g. pedagogy, assessment, digital). */
#[Fillable(['code', 'name_ar', 'name_en', 'sort_order'])]
class CompetencyDomain extends Model
{
    use HasUuids;
}
