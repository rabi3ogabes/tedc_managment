<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SchoolGroup;
use App\Models\TrainingNeed;
use Tests\TestCase;

class AccessScopeTest extends TestCase
{
    private function world(): array
    {
        [$a, $b, $c] = [$this->makeSchool(), $this->makeSchool(), $this->makeSchool()];
        $program = $this->makeProgram();
        $staff = [];
        foreach (['a' => $a, 'b' => $b, 'c' => $c] as $k => $school) {
            $staff[$k] = $this->makeEmployee(['school_id' => $school->id]);
            Registration::create(['program_id' => $program->id, 'employee_id' => $staff[$k]->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
            TrainingNeed::create(['school_id' => $school->id, 'skill_name' => 'Classroom management', 'employees_count' => 3, 'priority' => 'high', 'reason' => 'x', 'status' => 'submitted', 'submitted_by' => $staff[$k]->user_id]);
        }
        $group = SchoolGroup::create(['code' => 'G-1', 'name_ar' => 'مجموعة', 'name_en' => 'Group', 'type' => 'cluster']);
        $group->schools()->sync([$a->id, $b->id]);

        return [$a, $b, $c, $staff, $group, $program];
    }

    private function scopedUser(string $role, string $type, ?string $scopeId)
    {
        $user = $this->makeUser($role);
        $user->roles()->detach();
        $user->roles()->attach(Role::where('slug', $role)->value('id'), ['scope_type' => $type, 'scope_id' => $scopeId]);

        return $user->fresh();
    }

    public function test_a_school_group_user_sees_only_the_schools_of_the_group_in_every_list(): void
    {
        [$a, $b, $c, $staff, $group] = $this->world();
        $user = $this->scopedUser(Role::SCHOOL_ADMIN, 'school_group', $group->id);
        $inGroup = [$staff['a']->id, $staff['b']->id];

        $ids = fn (string $url) => collect($this->asUser($user)->getJson($url)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing($inGroup, $ids('/api/v1/admin/employees?per_page=100'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids('/api/v1/admin/schools?per_page=100'));
        $this->assertCount(2, $this->asUser($user)->getJson('/api/v1/admin/registrations?per_page=100')->json('data'));
        $needs = collect($this->asUser($user)->getJson('/api/v1/admin/training-needs?per_page=100')->json('data'))->pluck('school_id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $needs);

        $dashboard = $this->asUser($user)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.kpis');
        $this->assertSame(2, $dashboard['total_schools']);
        $this->assertSame(2, $dashboard['total_employees']);

        $this->asUser($user)->getJson("/api/v1/admin/schools/{$c->id}")->assertForbidden();
        $this->asUser($user)->getJson("/api/v1/admin/employees/{$staff['c']->id}")->assertForbidden();
        $this->asUser($user)->getJson("/api/v1/admin/employees/{$staff['a']->id}")->assertOk();
    }

    public function test_a_single_school_and_a_ministry_grant_reach_what_their_scope_says(): void
    {
        [$a, , , $staff] = $this->world();
        $school = $this->scopedUser(Role::SCHOOL_ADMIN, 'school', $a->id);
        $ministry = $this->scopedUser(Role::CENTER_ADMIN, 'ministry', null);

        $this->assertSame([$staff['a']->id], collect($this->asUser($school)->getJson('/api/v1/admin/employees?per_page=100')->json('data'))->pluck('id')->all());
        $this->assertCount(3, collect($this->asUser($ministry)->getJson('/api/v1/admin/employees?per_page=100')->json('data'))->whereIn('id', array_map(fn ($e) => $e->id, $staff)));
    }

    public function test_a_department_scope_reaches_only_that_departments_staff(): void
    {
        [$a, , , $staff] = $this->world();
        $d1 = Department::create(['school_id' => $a->id, 'code' => 'D1', 'name_ar' => 'قسم 1', 'name_en' => 'Dept 1']);
        $d2 = Department::create(['school_id' => $a->id, 'code' => 'D2', 'name_ar' => 'قسم 2', 'name_en' => 'Dept 2']);
        $other = $this->makeEmployee(['school_id' => $a->id, 'department_id' => $d2->id]);
        $staff['a']->update(['department_id' => $d1->id]);
        $user = $this->scopedUser(Role::SCHOOL_ADMIN, 'department', $d1->id);

        $ids = collect($this->asUser($user)->getJson('/api/v1/admin/employees?per_page=100')->json('data'))->pluck('id')->all();

        $this->assertSame([$staff['a']->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
