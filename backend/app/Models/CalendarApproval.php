<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Approval to run training on a day that is normally closed for it. */
#[Fillable(['date', 'reason', 'approved_by'])]
class CalendarApproval extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['date' => 'date:Y-m-d'];

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
