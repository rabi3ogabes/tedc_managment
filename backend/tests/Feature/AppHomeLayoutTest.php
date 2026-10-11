<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AppHomeLayoutTest extends TestCase
{
    public function test_each_kind_of_person_starts_with_their_own_view(): void
    {
        $trainee = $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/me/app-home')->assertOk()->json('data');
        $this->assertSame('employee', $trainee['role']);
        $this->assertSame(['programs', 'hours', 'todo', 'stats', 'scan', 'needs', 'dashboard', 'sessions', 'recommended'], $trainee['sections']);
        $this->assertSame([], $trainee['actions']);

        $trainer = $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/me/app-home')->assertOk()->json('data');
        $this->assertSame(['today', 'actions', 'dashboard'], $trainer['sections']);

        $exec = $this->asUser($this->makeUser(Role::EXECUTIVE))->getJson('/api/v1/me/app-home')->assertOk()->json('data');
        $this->assertSame('dashboard', $exec['sections'][0]);
        // The older mobile config still carries the trainee's cards.
        $this->assertSame($trainee['sections'], $this->getJson('/api/v1/public/mobile-config')->json('data.home.sections'));
    }

    public function test_action_tiles_show_only_what_is_waiting_and_only_to_people_who_can_act_on_it(): void
    {
        $employee = $this->makeEmployee();
        Registration::create(['program_id' => $this->makeProgram()->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_PENDING]);

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $tiles = $this->asUser($admin)->getJson('/api/v1/me/app-home')->assertOk()->json('data.actions');
        $byKey = array_column($tiles, null, 'key');
        $this->assertSame(1, $byKey['approvals_center']['count']);
        $this->assertArrayNotHasKey('assignments', $byKey);                 // nothing waiting: no tile
        $this->assertSame('/admin/approvals', $byKey['approvals_center']['web']);

        // A trainee has no actions card, so nothing is computed or sent for them.
        $this->assertSame([], $this->asUser($employee->user)->getJson('/api/v1/me/app-home')->json('data.actions'));
    }

    public function test_the_administrator_changes_one_role_without_touching_the_others(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $res = $this->asUser($admin)->putJson('/api/v1/admin/app-home', ['role' => 'trainer', 'sections' => [
            ['key' => 'actions', 'enabled' => true], ['key' => 'today', 'enabled' => false], ['key' => 'approvals_manager', 'enabled' => false],
        ]])->assertOk();
        $this->assertTrue(collect($res->json('data'))->firstWhere('role', 'trainer')['customised']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('role', 'employee')['customised']);
        Cache::flush();

        $trainer = $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/me/app-home')->assertOk()->json('data');
        $this->assertSame(['actions', 'dashboard'], array_values(array_diff($trainer['sections'], ['hours', 'todo', 'stats', 'scan', 'needs', 'programs', 'sessions', 'recommended'])));
        $this->assertSame(['programs', 'hours', 'todo', 'stats', 'scan', 'needs', 'dashboard', 'sessions', 'recommended'], $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/me/app-home')->json('data.sections'));

        $this->asUser($admin)->deleteJson('/api/v1/admin/app-home?role=trainer')->assertOk();
        Cache::flush();
        $this->assertSame(['today', 'actions', 'dashboard'], $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/me/app-home')->json('data.sections'));
    }

    public function test_only_settings_managers_change_it_and_bad_input_is_refused(): void
    {
        $body = ['role' => 'trainer', 'sections' => [['key' => 'hours', 'enabled' => true]]];
        $this->asUser($this->makeUser())->putJson('/api/v1/admin/app-home', $body)->assertForbidden();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        foreach ([
            ['role' => 'janitor', 'sections' => [['key' => 'hours', 'enabled' => true]]],
            ['role' => 'trainer', 'sections' => [['key' => 'nope', 'enabled' => true]]],
            ['role' => 'trainer', 'sections' => [['key' => 'hours', 'enabled' => true], ['key' => 'hours', 'enabled' => false]]],
        ] as $bad) {
            $this->asUser($admin)->putJson('/api/v1/admin/app-home', $bad)->assertStatus(422);
        }
        $this->asUser($admin)->deleteJson('/api/v1/admin/app-home?role=janitor')->assertStatus(422);
    }
}
