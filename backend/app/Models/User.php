<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\ActiveRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

#[Fillable(['auth_id', 'name', 'name_ar', 'email', 'phone', 'password', 'locale', 'avatar_path', 'status', 'last_login_at', 'active_role_user_id'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery'])]
class User extends Authenticatable
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    private ?Collection $permissionCache = null;

    /** What the current request does to the data: add, edit, delete, or null when it only reads (WriteAction). */
    private ?string $acting = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_confirmed_at' => 'datetime',
            'mfa_enabled' => 'boolean',
            'mfa_recovery' => 'array',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->using(RoleUser::class)->withPivot(['id', 'scope_type', 'scope_id', 'granted_by', 'granted_at', 'expires_at', 'granted_permissions', 'revoked_permissions'])->withTimestamps();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleUser::class);
    }

    /**
     * The roles that count right now: the active role during a request (a person works as one role at a time),
     * otherwise — console, jobs — every role that has not expired.
     *
     * @return Collection<int, Role>
     */
    public function effectiveRoles(): Collection
    {
        $context = app(ActiveRole::class);
        if ($context->isSet($this)) {
            $active = $context->for($this);

            return $active ? collect([$active->role]) : collect();
        }

        return $this->roles->filter(fn (Role $r) => $r->pivot->expires_at === null || $r->pivot->expires_at->isFuture())->values();
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function trainer(): HasOne
    {
        return $this->hasOne(Trainer::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function hasRole(string ...$slugs): bool
    {
        return $this->effectiveRoles()->pluck('slug')->intersect($slugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * The grants that count right now: the active one during a request (a person works as one role at a time),
     * otherwise — console, jobs — every grant that has not expired.
     *
     * @return Collection<int, RoleUser>
     */
    public function effectiveGrants(): Collection
    {
        $context = app(ActiveRole::class);
        if ($context->isSet($this)) {
            $active = $context->for($this);

            return $active ? collect([$active]) : collect();
        }

        return $this->roleAssignments()->with('role.permissions:id,slug')->get()->filter(fn (RoleUser $grant) => $grant->role !== null && $grant->isActive())->values();
    }

    /**
     * What the person may do now: the rights of the role they work in, with what an administrator added for them in that
     * role and without what was taken from them in it (RoleUser::effectivePermissions).
     */
    public function permissionSlugs(): Collection
    {
        return $this->permissionCache ??= $this->effectiveGrants()
            ->flatMap(fn (RoleUser $grant) => $grant->effectivePermissions())
            ->unique()
            ->values();
    }

    /**
     * Whether the person may use a right for what this request does. A request that adds, edits or deletes (acting) only
     * counts a right when the action was not taken from the person in the right's area (RoleUser::allows): somebody given
     * a page "to view only" keeps reading it and is refused every change to it, wherever the check is made.
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if (! $this->permissionSlugs()->contains($slug)) {
            return false;
        }

        return $this->acting === null || $this->effectiveGrants()->contains(fn (RoleUser $grant) => $grant->allows($slug, $this->acting));
    }

    /** Sets what the current request does to the data (WriteAction), or null for a request that only reads. */
    public function acting(?string $action): void
    {
        $this->acting = $action;
    }

    /**
     * Whether one of these rights is held but refused only because of what the request does: the answer to "is this a
     * page the person may read but not change?", for a clearer refusal than "not allowed".
     *
     * @param  list<string>  $slugs
     */
    public function limitedOn(array $slugs): ?string
    {
        if ($this->acting === null || $this->isSuperAdmin()) {
            return null;
        }

        return collect($slugs)->contains(fn (string $slug) => $this->permissionSlugs()->contains($slug)) ? $this->acting : null;
    }

    /**
     * The pages hidden from the person in the role they work in, and the actions taken from them per area.
     *
     * @return array{hidden_pages: list<string>, denied_actions: array<string, list<string>>}
     */
    public function pageLimits(): array
    {
        $grants = $this->effectiveGrants();
        if ($this->isSuperAdmin() || $grants->count() !== 1) {
            return ['hidden_pages' => [], 'denied_actions' => []];
        }
        $grant = $grants->first();

        return ['hidden_pages' => array_values($grant->hidden_pages ?? []), 'denied_actions' => $grant->denied_actions ?? []];
    }

    public function displayName(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'ar' && $this->name_ar) ? $this->name_ar : $this->name;
    }
}
