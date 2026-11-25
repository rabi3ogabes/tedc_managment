<?php

namespace App\Support;

use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The role a request is made in. A person with several roles (trainer, trainee, manager…) works as one at a time:
 * only the active role's permissions and scope count. It is chosen by the `X-Active-Role` header (a role-grant id),
 * else the one the user last switched to, else their highest role.
 */
class ActiveRole
{
    private ?RoleUser $assignment = null;

    private ?string $userId = null;

    /** @return Collection<int, RoleUser> the user's grants that have not expired, with their roles */
    public function assignments(User $user): Collection
    {
        return RoleUser::with('role')->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()->filter(fn (RoleUser $a) => $a->role !== null)->sortByDesc(fn (RoleUser $a) => $a->role->level)->values();
    }

    /** The grant to use when nothing is requested: the remembered one if still valid, else the highest. */
    public function defaultFor(User $user, ?Collection $assignments = null): ?RoleUser
    {
        $assignments ??= $this->assignments($user);

        return ($user->active_role_user_id ? $assignments->firstWhere('id', $user->active_role_user_id) : null) ?? $assignments->first();
    }

    public function set(User $user, ?RoleUser $assignment): void
    {
        $this->userId = $user->id;
        $this->assignment = $assignment;
        $user->forgetPermissionCache();
    }

    public function clear(): void
    {
        $this->userId = null;
        $this->assignment = null;
    }

    /** The grant this request runs as, if it belongs to this user. */
    public function for(?User $user): ?RoleUser
    {
        return $user && $this->userId === $user->id ? $this->assignment : null;
    }

    public function isSet(?User $user): bool
    {
        return $user !== null && $this->userId === $user->id;
    }
}
