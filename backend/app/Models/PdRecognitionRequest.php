<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pd_activity_id', 'requested_equivalent_program_id', 'center_decision', 'recognised_hours', 'equivalent_program_ids', 'decided_by', 'note', 'decided_at'])]
class PdRecognitionRequest extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['equivalent_program_ids' => 'array', 'decided_at' => 'datetime', 'recognised_hours' => 'float'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PdActivity::class, 'pd_activity_id');
    }
}
