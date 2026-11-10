<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_ar', 'name_en', 'hour_rules', 'evidence_required', 'is_active'])]
class PdActivityType extends Model
{
    use Auditable, HasTranslations, HasUuids;

    protected $casts = ['hour_rules' => 'array', 'evidence_required' => 'boolean', 'is_active' => 'boolean'];
}
