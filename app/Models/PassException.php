<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A documented exception to one passing criterion (typically attendance). */
#[Fillable(['registration_id', 'criterion', 'reason', 'attachment_path', 'attachment_name', 'granted_by', 'granted_at', 'revoked_at', 'revoked_by'])]
class PassException extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
