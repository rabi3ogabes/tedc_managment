<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
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

#[Fillable(['auth_id', 'name', 'name_ar', 'email', 'phone', 'password', 'locale', 'avatar_path', 'status', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    private ?Collection $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
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
        return $this->roles->pluck('slug')->intersect($slugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function permissionSlugs(): Collection
    {
        return $this->permissionCache ??= $this->roles()
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

    /**
     * School the user administers (school admins are linked through their employee record).
     */
    public function managedSchoolId(): ?string
    {
        return $this->hasRole(Role::SCHOOL_ADMIN) ? $this->employee?->school_id : null;
    }

    public function displayName(?string $locale = null): string
    {
        return (($locale ?? app()->getLocale()) === 'ar' && $this->name_ar) ? $this->name_ar : $this->name;
    }
}
