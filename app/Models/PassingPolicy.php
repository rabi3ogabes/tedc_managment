<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** How a trainee passes: criteria, weights, threshold, certificate types and task approval. Group beats program beats global. */
#[Fillable(['scope', 'scope_id', 'mode', 'criteria', 'pass_threshold', 'assessment_ids', 'participation_rules', 'allow_test_out', 'test_out_assessment_id', 'hours_mode', 'certificate_types', 'attendance_certificate_min', 'survey_required_for_download', 'task_approval', 'certificate_templates', 'updated_by'])]
class PassingPolicy extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['criteria' => 'array', 'assessment_ids' => 'array', 'participation_rules' => 'array', 'certificate_templates' => 'array', 'pass_threshold' => 'float', 'attendance_certificate_min' => 'float', 'allow_test_out' => 'boolean', 'survey_required_for_download' => 'boolean'];
    }
}
