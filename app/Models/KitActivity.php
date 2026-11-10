<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kit_id', 'user_id', 'action', 'subject_type', 'subject_id', 'meta'])]
class KitActivity extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'kit_activity';

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
