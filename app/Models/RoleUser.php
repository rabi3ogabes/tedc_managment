<?php

namespace App\Models;

use App\Support\WriteAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

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
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime', 'granted_permissions' => 'array', 'revoked_permissions' => 'array', 'hidden_pages' => 'array', 'denied_actions' => 'array'];
    }

    /**
     * The rights this grant gives its holder: the role's own, plus the ones added for this person, minus the ones taken
     * from this person. With nothing added or taken it is the role's rights, as it always was.
     *
     * @return Collection<int, string>
     */
    public function effectivePermissions(): Collection
    {
        $this->loadMissing('role.permissions:id,slug');

        return ($this->role?->permissions->pluck('slug') ?? collect())
            ->merge($this->granted_permissions ?? [])
            ->diff($this->revoked_permissions ?? [])
            ->unique()->values();
    }

    /**
     * Whether this grant lets its holder do `$action` (add, edit, delete) with a right: the grant carries the right and
     * the action was not taken from the person in the right's area. Reading is never limited here.
     */
    public function allows(string $slug, ?string $action): bool
    {
        if (! $this->effectivePermissions()->contains($slug)) {
            return false;
        }

        return $action === null || ! in_array($action, $this->denied_actions[WriteAction::area($slug)] ?? [], true);
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
