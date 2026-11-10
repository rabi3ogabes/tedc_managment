<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A submission of an external registration form. */
#[Fillable(['number', 'form_id', 'data', 'email', 'phone', 'national_id_hash', 'email_verified_at', 'status', 'reviewer_id', 'decision_note', 'user_id', 'snapshot_path'])]
class RegistrationRequest extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['data' => 'array', 'email_verified_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(RegistrationForm::class, 'form_id');
    }
}
