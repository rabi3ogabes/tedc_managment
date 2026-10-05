<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The annual training plan: drafted from needs, reviewed, approved, executed and monitored. */
#[Fillable(['year', 'version', 'title_ar', 'title_en', 'status', 'rules', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'baseline', 'signed_pdf_path', 'notes'])]
class TrainingPlan extends Model
{
    use Auditable, HasUuids;

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    protected function casts(): array
    {
        return ['rules' => 'array', 'baseline' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TrainingPlanItem::class, 'plan_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(TrainingPlanChange::class, 'plan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Once approved, every change is logged with a reason. */
    public function isBaselined(): bool
    {
        return in_array($this->status, [self::APPROVED, self::ACTIVE, self::CLOSED], true);
    }
}
