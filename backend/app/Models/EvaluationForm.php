<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'title_ar', 'title_en', 'questions', 'version', 'approval_status', 'approval_note', 'approved_by', 'approved_at', 'is_default', 'is_system', 'settings', 'created_by'])]
class EvaluationForm extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['questions' => 'array', 'settings' => 'array', 'approved_at' => 'datetime', 'is_default' => 'boolean', 'is_system' => 'boolean'];
}
