<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['path_id', 'level_no', 'title_ar', 'title_en', 'conditions', 'required_programs', 'min_pd_hours', 'validity_months', 'renewal_conditions'])]
class CareerPathLevel extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['conditions' => 'array', 'required_programs' => 'array', 'renewal_conditions' => 'array', 'min_pd_hours' => 'float'];
}
