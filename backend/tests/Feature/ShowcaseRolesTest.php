<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\ShowcaseAccounts;
use Tests\TestCase;

/**
 * Every showcase account signs in and finds what its role is meant to have (docs/roles.md): the screens it should
 * open answer, the ones it should not are refused, and the sample data is there to try them on.
 */
class ShowcaseRolesTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();
    }

    private function account(string $key): User
    {
        return User::where('email', ShowcaseAccounts::email($key))->firstOrFail();
    }

    /** @param  array<string, list<string>>  $open  @param  list<string>  $closed */
    private function expect(string $key, array $open, array $closed): void
    {
        $user = $this->account($key);
        foreach ($open as $uri) {
            $this->asUser($user)->getJson("/api/v1/{$uri}")->assertOk("{$key} should open {$uri}");
        }
        foreach ($closed as $uri) {
            $this->asUser($user)->getJson("/api/v1/{$uri}")->assertForbidden("{$key} should be refused {$uri}");
        }
    }

    public function test_every_showcase_account_signs_in_the_way_the_dashboard_and_the_app_do(): void
    {
        foreach (array_keys(ShowcaseAccounts::ACCOUNTS) as $key) {
            // Web: the dashboard opens the account (audited, one hour) — and the page behind it answers as that person.
            $this->app['auth']->forgetGuards();
            $res = $this->asUser($this->admin)->postJson("/api/v1/admin/showcase-accounts/{$key}/open")->assertOk();
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $this->withToken($res->json('access_token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', ShowcaseAccounts::email($key));
            $this->flushHeaders();

            // App: a one-time password from the dashboard signs in with the address.
            $this->app['auth']->forgetGuards();
            $password = $this->asUser($this->admin)->postJson("/api/v1/admin/showcase-accounts/{$key}/app-password")->assertOk()->json('data.password');
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $login = $this->postJson('/api/v1/auth/login', ['email' => ShowcaseAccounts::email($key), 'password' => $password])->assertOk();
            $this->assertNotEmpty($login->json('access_token'), $key);
            $this->flushHeaders();
        }
    }

    public function test_the_system_administrator_opens_every_area(): void
    {
        $this->expect('admin', [
            'admin/saber/overview', 'admin/saber/programs', 'admin/programs', 'admin/registrations', 'admin/employees', 'admin/schools',
            'admin/reports', 'admin/certificates', 'admin/surveys', 'admin/users', 'admin/roles', 'admin/audit-logs', 'admin/error-logs',
            'admin/showcase-accounts', 'admin/trainers',
        ], []);
    }

    public function test_the_supervisor_has_his_staff_and_no_admin_areas(): void
    {
        $this->expect('supervisor-1', ['me/team', 'me/registrations', 'me/notifications', 'me/needs', 'admin/employees', 'admin/surveys'], [
            'admin/saber/overview', 'admin/roles', 'admin/users', 'admin/registrations', 'admin/reports', 'admin/error-logs', 'admin/showcase-accounts', 'admin/audit-logs',
        ]);
        $team = $this->asUser($this->account('supervisor-1'))->getJson('/api/v1/me/team')->assertOk()->json('data');
        $this->assertCount(2, $team, 'the two demo trainees are his staff');
    }

    public function test_the_trainer_has_his_programmes_and_no_admin_areas(): void
    {
        $this->expect('trainer-1', ['admin/programs', 'admin/surveys', 'admin/trainers', 'me/tasks', 'me/notifications'], [
            'admin/saber/overview', 'admin/roles', 'admin/users', 'admin/registrations', 'admin/employees', 'admin/reports', 'admin/error-logs', 'admin/showcase-accounts',
        ]);
        $mine = collect($this->asUser($this->account('trainer-1'))->getJson('/api/v1/admin/programs')->assertOk()->json('data'))->pluck('code');
        $this->assertTrue($mine->contains(fn ($c) => str_starts_with((string) $c, ShowcaseAccounts::PREFIX.'-')), 'the demo trainer sees the sample programme he teaches');
    }

    public function test_the_trainee_has_his_own_training_and_no_admin_areas(): void
    {
        $this->expect('trainee-1', [
            'me/registrations', 'me/tasks', 'me/assessments', 'me/certificates', 'me/evaluations', 'me/surveys', 'me/needs', 'me/notifications', 'me/programs-for-me',
        ], [
            'admin/programs', 'admin/saber/overview', 'admin/roles', 'admin/users', 'admin/registrations', 'admin/employees', 'admin/reports', 'admin/error-logs', 'me/team',
        ]);
        $mine = $this->asUser($this->account('trainee-1'))->getJson('/api/v1/me/registrations')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(3, count($mine), 'the running, the finished and the upcoming sample programme');
    }

    public function test_the_three_heads_open_what_their_role_runs(): void
    {
        $this->expect('centre-head', ['admin/saber/overview', 'admin/programs', 'admin/registrations', 'admin/reports', 'admin/employees', 'admin/users'], ['admin/error-logs', 'admin/showcase-accounts']);
        $this->expect('training-head', ['admin/saber/overview', 'admin/programs', 'admin/registrations'], ['admin/error-logs', 'admin/showcase-accounts']);
        $this->expect('planning-head', ['admin/programs', 'admin/reports'], ['admin/error-logs', 'admin/showcase-accounts']);
        $this->assertGreaterThan(0, Program::where('code', 'like', ShowcaseAccounts::PREFIX.'-%')->count());
    }
}
