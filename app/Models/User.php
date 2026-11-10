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
        return $this->belongsToMany(Role::class)->using(RoleUser::class)->withPivot(['id', 'scope_type', 'scope_id', 'granted_by', 'granted_at', 'expires_at'])->withTimestamps();
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

    public function permissionSlugs(): Collection
    {
        return $this->permissionCache ??= Role::whereIn('id', $this->effectiveRoles()->pluck('id'))
            ->with('permissions:id,slug')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || $this->permissionSlugs()->contains($slug);
    }

    public function displayName(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'ar' && $this->name_ar) ? $this->name_ar : $this->name;
    }
}
