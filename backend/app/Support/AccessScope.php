<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SchoolGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the active role may see: the whole Ministry, a school group, one school or one department.
 * Every list, report and dashboard asks this one class, so a school-group user never meets another group's data.
 */
class AccessScope
{
    /** @param  array<int, string>|null  $schoolIds  null = every school */
    private function __construct(private readonly string $type, private readonly ?array $schoolIds, private readonly ?array $departmentIds) {}

    public static function ministry(): self
    {
        return new self('ministry', null, null);
    }

    /** The scope of the role the current request runs as. */
    public static function current(?User $user = null): self
    {
        $user ??= request()->user();
        if (! $user) {
            return new self('none', [], []);
        }
        $context = app(ActiveRole::class);
        $grant = $context->isSet($user) ? $context->for($user) : $context->defaultFor($user);

        return self::forGrant($user, $grant);
    }

    public static function forGrant(User $user, ?RoleUser $grant): self
    {
        if (! $grant) {
            return new self('none', [], []);
        }
        $role = $grant->role;
        $levels = $role->scope_levels;
        $ministryAllowed = $levels === null || in_array('ministry', $levels, true);

        if ($grant->scope_type === 'school_group' && $grant->scope_id) {
            return new self('school_group', SchoolGroup::find($grant->scope_id)?->schools()->pluck('schools.id')->all() ?? [], null);
        }
        if ($grant->scope_type === 'school' && $grant->scope_id) {
            return new self('school', [$grant->scope_id], null);
        }
        if ($grant->scope_type === 'department' && $grant->scope_id) {
            $department = Department::find($grant->scope_id);

            return new self('department', $department?->school_id ? [$department->school_id] : null, [$grant->scope_id]);
        }
        if ($grant->scope_type === 'ministry' && ! $ministryAllowed) {
            // A school-level role granted without a scope works inside the school of the person's employee record, never Ministry-wide.
            $school = $user->employee?->school_id;

            return new self($school ? 'school' : 'none', $school ? [$school] : [], null);
        }

        return self::ministry();
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isMinistryWide(): bool
    {
        return $this->type === 'ministry';
    }

    /** @return array<int, string>|null null when every school is visible */
    public function schoolIds(): ?array
    {
        return $this->schoolIds;
    }

    /** @return array<int, string>|null */
    public function departmentIds(): ?array
    {
        return $this->departmentIds;
    }

    /** True when the scope reaches exactly one school (the old "school administrator" case). */
    public function singleSchoolId(): ?string
    {
        return $this->schoolIds !== null && count($this->schoolIds) === 1 ? $this->schoolIds[0] : null;
    }

    public function allowsSchool(?string $schoolId): bool
    {
        if ($this->type === 'none') {
            return false;
        }

        return $this->schoolIds === null || ($schoolId !== null && in_array($schoolId, $this->schoolIds, true));
    }

    /** Employees the scope reaches (query on `employees`). */
    public function constrainEmployees(Builder $query, string $prefix = ''): Builder
    {
        if ($this->type === 'none') {
            return $query->whereRaw('1 = 0');
        }
        if ($this->departmentIds !== null) {
            $query->whereIn($prefix.'department_id', $this->departmentIds);
        }
        if ($this->schoolIds !== null) {
            $query->whereIn($prefix.'school_id', $this->schoolIds);
        }

        return $query;
    }

    /** Rows that belong to an employee (registrations, certificates, attendance…): limited through the `employee` relation. */
    public function constrainThroughEmployee(Builder $query, string $relation = 'employee'): Builder
    {
        return $this->isMinistryWide() ? $query : $query->whereHas($relation, fn ($e) => $this->constrainEmployees($e));
    }

    /** Rows that carry a school id (training needs, schools themselves with `id`). */
    public function constrainSchoolColumn(Builder $query, string $column = 'school_id'): Builder
    {
        if ($this->type === 'none') {
            return $query->whereRaw('1 = 0');
        }

        return $this->schoolIds === null ? $query : $query->whereIn($column, $this->schoolIds);
    }

    public function allowsEmployee(Employee $employee): bool
    {
        if ($this->type === 'none') {
            return false;
        }
        if ($this->departmentIds !== null && ! in_array($employee->department_id, $this->departmentIds, true)) {
            return false;
        }

        return $this->allowsSchool($employee->school_id);
    }

    /** Roles whose scope is below the Ministry ask for the narrowed view even when they hold no explicit scope. */
    public static function roleIsSchoolLevel(Role $role): bool
    {
        return $role->scope_levels !== null && ! in_array('ministry', $role->scope_levels, true);
    }
}
