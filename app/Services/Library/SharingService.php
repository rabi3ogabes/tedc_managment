<?php

namespace App\Services\Library;

use App\Exceptions\BusinessRuleException;
use App\Models\CourseLesson;
use App\Models\JobGroup;
use App\Models\KitFile;
use App\Models\LibraryItem;
use App\Models\Material;
use App\Models\Registration;
use App\Models\ResourceShare;
use App\Models\SharingPolicy;
use App\Models\User;
use Illuminate\Support\Collection;

/** Sharing resources with courses, groups, job groups, roles and people under per-role policies that protect rights holders. */
class SharingService
{
    public const TYPES = ['material', 'kit_file', 'library_item', 'lesson'];

    public const TARGETS = ['program', 'group', 'job_group', 'role', 'user'];

    public function __construct(private readonly JobGroupService $groups) {}

    /** The union of the policies of the user's roles; staff with sharing.manage are not limited. @return array{resource_types: list<string>, target_types: list<string>, allow_reshare: bool, allow_download: bool, watermark: bool} */
    public function policyFor(User $user): array
    {
        if ($user->hasPermission('sharing.manage')) {
            $base = ['resource_types' => self::TYPES, 'target_types' => self::TARGETS, 'allow_reshare' => true, 'allow_download' => true, 'watermark' => false];
        } else {
            $base = ['resource_types' => [], 'target_types' => [], 'allow_reshare' => false, 'allow_download' => false, 'watermark' => false];
        }
        foreach (SharingPolicy::whereIn('role', $user->roles()->pluck('slug'))->get() as $p) {
            $base['resource_types'] = array_values(array_unique(array_merge($base['resource_types'], $p->resource_types)));
            $base['target_types'] = array_values(array_unique(array_merge($base['target_types'], $p->target_types)));
            $base['allow_reshare'] = $base['allow_reshare'] || $p->allow_reshare;
            $base['allow_download'] = $base['allow_download'] || $p->allow_download;
            $base['watermark'] = $base['watermark'] || $p->watermark;
        }

        return $base;
    }

    /** True when the item may not leave the platform (the rights say so, or the policy forbids downloads). */
    public function isViewOnly(string $type, string $id, array $policy): bool
    {
        if ($type === 'library_item') {
            $rights = LibraryItem::find($id)?->rights ?? [];
            if (($rights['download'] ?? true) === false) {
                return true;
            }
        }

        return ! $policy['allow_download'];
    }

    public function share(User $by, string $type, string $id, string $targetType, string $targetId, string $permission, ?\DateTimeInterface $expires): ResourceShare
    {
        $policy = $this->policyFor($by);
        if (! in_array($type, $policy['resource_types'], true) || ! in_array($targetType, $policy['target_types'], true)) {
            throw new BusinessRuleException(__('messages.sharing.not_allowed'), 'share_not_allowed');
        }
        abort_unless($this->exists($type, $id), 404);
        if ($permission === 'reshare' && ! $policy['allow_reshare']) {
            throw new BusinessRuleException(__('messages.sharing.no_reshare'), 'share_no_reshare');
        }
        // Rights holders: a view-only item cannot be shared with download or reshare rights.
        if ($this->isViewOnly($type, $id, $policy) && $permission !== 'view') {
            $permission = 'view';
        }
        // A job group may be addressed by its own members, or by sharing managers.
        if ($targetType === 'job_group' && ! $by->hasPermission('sharing.manage')) {
            $employee = $by->employee;
            $group = JobGroup::findOrFail($targetId);
            if (! $employee || ! $this->groups->matches($group, $employee)) {
                throw new BusinessRuleException(__('messages.sharing.not_member'), 'share_not_member');
            }
        }
        // Someone who only holds a re-share right may pass on at most what they were given.
        if (! $by->hasPermission('sharing.manage') && $this->received($by, $type, $id) === null && ! $this->ownsResource($by, $type, $id)) {
            throw new BusinessRuleException(__('messages.sharing.not_allowed'), 'share_not_allowed');
        }

        return ResourceShare::updateOrCreate(['resource_type' => $type, 'resource_id' => $id, 'target_type' => $targetType, 'target_id' => $targetId], ['permission' => $permission, 'shared_by' => $by->id, 'expires_at' => $expires]);
    }

    private function exists(string $type, string $id): bool
    {
        return match ($type) {
            'material' => Material::whereKey($id)->exists(), 'kit_file' => KitFile::whereKey($id)->exists(), 'library_item' => LibraryItem::whereKey($id)->exists(), 'lesson' => CourseLesson::whereKey($id)->exists(), default => false,
        };
    }

    private function ownsResource(User $u, string $type, string $id): bool
    {
        return match ($type) {
            'material' => Material::whereKey($id)->where('uploaded_by', $u->id)->exists(), 'library_item' => LibraryItem::whereKey($id)->where('created_by', $u->id)->exists(), default => $u->hasPermission('programs.manage') || $u->hasPermission('kits.manage'),
        };
    }

    /** The share that gave the user access to the resource, if any. */
    public function received(User $user, string $type, string $id): ?ResourceShare
    {
        return $this->visibleShares($user)->first(fn ($s) => $s->resource_type === $type && $s->resource_id === $id);
    }

    /** @return Collection<int, ResourceShare> */
    public function visibleShares(User $user)
    {
        $employee = $user->employee;
        $programs = $employee ? Registration::where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->pluck('program_id')->all() : [];
        $groupIds = $employee ? Registration::where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->whereNotNull('training_group_id')->pluck('training_group_id')->all() : [];
        $jobGroups = $employee ? $this->groups->groupsOf($employee) : [];
        $roles = $user->roles()->pluck('slug')->all();

        return ResourceShare::where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('target_type', 'user')->where('target_id', $user->id))->orWhere(fn ($w) => $w->where('target_type', 'program')->whereIn('target_id', $programs))
                ->orWhere(fn ($w) => $w->where('target_type', 'group')->whereIn('target_id', $groupIds))->orWhere(fn ($w) => $w->where('target_type', 'job_group')->whereIn('target_id', $jobGroups))->orWhere(fn ($w) => $w->where('target_type', 'role')->whereIn('target_id', $roles)))->get();
    }
}
