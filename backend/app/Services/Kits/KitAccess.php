<?php

namespace App\Services\Kits;

use App\Models\KitMember;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and do what on a training kit. Center staff work on every kit; kit developers
 * (معد الحقيبة) and QA reviewers (فريق ضمان الجودة) work on the kits they are members of.
 */
class KitAccess
{
    public static function isStaff(User $user): bool
    {
        return $user->hasRole(...Role::CENTER_STAFF);
    }

    public static function memberRole(User $user, TrainingKit $kit): ?string
    {
        if ($kit->owner_id === $user->id) {
            return KitMember::DEVELOPER;
        }

        return $kit->relationLoaded('members')
            ? $kit->members->firstWhere('user_id', $user->id)?->role
            : $kit->members()->where('user_id', $user->id)->value('role');
    }

    /** Restricts a kit query to what the user may see. */
    public static function scope(Builder $query, User $user): Builder
    {
        if (self::isStaff($user)) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)));
    }

    public static function canView(User $user, TrainingKit $kit): bool
    {
        return $user->hasPermission('kits.view') && (self::isStaff($user) || self::memberRole($user, $kit) !== null);
    }

    /** Edit kit details, upload / delete files, manage members. */
    public static function canManage(User $user, TrainingKit $kit): bool
    {
        if (! $user->hasPermission('kits.manage') || ! in_array($kit->status, TrainingKit::EDITABLE, true)) {
            return false;
        }

        return self::isStaff($user) || self::memberRole($user, $kit) === KitMember::DEVELOPER;
    }

    /** Edit slides and files: the kit developers and the QA team both work on the deck. */
    public static function canEditContent(User $user, TrainingKit $kit): bool
    {
        if (! $user->hasPermission('kits.manage') && ! $user->hasPermission('kits.review')) {
            return false;
        }
        if (! in_array($kit->status, TrainingKit::EDITABLE, true)) {
            return false;
        }
        $role = self::memberRole($user, $kit);

        return self::isStaff($user) || in_array($role, [KitMember::DEVELOPER, KitMember::QA, KitMember::REVIEWER], true);
    }

    public static function canReview(User $user, TrainingKit $kit): bool
    {
        if (! $user->hasPermission('kits.review')) {
            return false;
        }
        $role = self::memberRole($user, $kit);

        return self::isStaff($user) || in_array($role, [KitMember::QA, KitMember::REVIEWER], true);
    }

    public static function canComment(User $user, TrainingKit $kit): bool
    {
        return self::canView($user, $kit) && (self::isStaff($user) || self::memberRole($user, $kit) !== KitMember::VIEWER);
    }

    public static function canGenerate(User $user, TrainingKit $kit): bool
    {
        return $user->hasPermission('kits.generate') && self::canEditContent($user, $kit);
    }

    public static function canPublish(User $user): bool
    {
        return $user->hasPermission('kits.publish');
    }
}
