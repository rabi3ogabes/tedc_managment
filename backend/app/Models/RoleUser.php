<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** A role granted to a user at a scope: Ministry-wide, one school group, one school or one department. */
class RoleUser extends Pivot
{
    use HasUuids;

    public const SCOPES = ['ministry', 'school_group', 'school', 'department'];

    public $incrementing = false;

    protected $table = 'role_user';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
