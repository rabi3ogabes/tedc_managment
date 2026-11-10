<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SchoolGroup;
use App\Models\Trainer;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns an audience filter into people. Different filters narrow each other (roles AND schools AND programs…); the values inside one
 * filter widen it (any of these schools). `user_ids` adds named people on top. A sender whose scope is a few schools only reaches those schools.
 *
 * Filter keys: all, roles[] (slugs), job_titles[], schools[], school_groups[], programs[], registration_statuses[], trainers, supervisors, user_ids[].
 */
class AudienceResolver
{
    public const KEYS = ['roles', 'job_titles', 'schools', 'school_groups', 'programs', 'registration_statuses', 'trainers', 'supervisors', 'user_ids'];

    public function isEmpty(?array $filter): bool
    {
        foreach (self::KEYS as $k) {
            if (! empty($filter[$k])) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, string> user ids */
    public function resolve(?array $filter, ?User $sender = null): Collection
    {
        $filter ??= [];
        $users = $this->query($filter, $sender);
        $ids = $users->pluck('users.id');

        if (! empty($filter['user_ids'])) {
            $extra = User::whereIn('id', (array) $filter['user_ids'])->where('status', 'active');
            if ($sender && ! AccessScope::current($sender)->isMinistryWide()) {
                $scope = AccessScope::current($sender);
                $extra->whereHas('employee', fn ($e) => $scope->constrainEmployees($e));
            }
            $ids = $ids->merge($extra->pluck('id'));
        }

        return $ids->unique()->values();
    }

    /** The number of people and a few names, for the screen that builds the audience. @return array{count: int, sample: list<array{id: string, name: string, school: ?string}>} */
    public function preview(?array $filter, ?User $sender = null): array
    {
        $ids = $this->resolve($filter, $sender);
        $sample = User::with('employee.school')->whereIn('id', $ids->take(8))->get()->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->displayName(), 'school' => $u->employee?->school?->translate('name'),
        ])->all();

        return ['count' => $ids->count(), 'sample' => $sample];
    }

    /** Of these people, who falls inside the filter (a rule applies to some of the recipients only). @param  Collection<int, string>|array<int, string>  $userIds @return array<int, string> */
    public function matching(Collection|array $userIds, ?array $filter): array
    {
        $userIds = collect($userIds)->values();
        if ($this->isEmpty($filter)) {
            return $userIds->all();
        }

        return $this->query($filter ?? [], null)->whereIn('users.id', $userIds)->pluck('users.id')->all();
    }

    private function query(array $filter, ?User $sender): Builder
    {
        $q = User::query()->where('users.status', 'active')
            ->whereDoesntHave('roles', fn ($r) => $r->where('slug', Role::SUPER_ADMIN));

        $q->when(! empty($filter['roles']), fn ($b) => $b->whereHas('roles', fn ($r) => $r->whereIn('slug', (array) $filter['roles'])));

        $employee = fn (callable $f) => $q->whereHas('employee', $f);
        if (! empty($filter['job_titles'])) {
            $employee(fn ($e) => $e->whereIn('job_title_id', (array) $filter['job_titles']));
        }
        if (! empty($filter['schools'])) {
            $employee(fn ($e) => $e->whereIn('school_id', (array) $filter['schools']));
        }
        if (! empty($filter['school_groups'])) {
            $schools = SchoolGroup::with('schools:id')->whereIn('id', (array) $filter['school_groups'])->get()->flatMap(fn ($g) => $g->schools->pluck('id'));
            $employee(fn ($e) => $e->whereIn('school_id', $schools));
        }
        if (! empty($filter['programs'])) {
            $statuses = $filter['registration_statuses'] ?? [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING];
            $employee(fn ($e) => $e->whereIn('id', Registration::whereIn('program_id', (array) $filter['programs'])->whereIn('status', (array) $statuses)->select('employee_id')));
        }
        if (! empty($filter['trainers'])) {
            $q->whereIn('users.id', Trainer::whereNotNull('user_id')->where('status', 'active')->select('user_id'));
        }
        if (! empty($filter['supervisors'])) {
            $employee(fn ($e) => $e->whereIn('id', Employee::whereNotNull('supervisor_id')->select('supervisor_id')));
        }

        if ($sender) {
            $scope = AccessScope::current($sender);
            if (! $scope->isMinistryWide()) {
                $q->whereHas('employee', fn ($e) => $scope->constrainEmployees($e));
            }
        }

        return $q;
    }
}
