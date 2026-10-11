<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\AppThemes;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AppThemesTest extends TestCase
{
    public function test_nobody_has_a_look_until_the_administrator_assigns_one(): void
    {
        $employee = $this->makeEmployee();
        $this->asUser($employee->user)->getJson('/api/v1/me/app-theme')->assertOk()->assertJsonPath('data', null);

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $show = $this->asUser($admin)->getJson('/api/v1/admin/app-themes')->assertOk();
        $this->assertCount(count(AppThemes::presets()), $show->json('data.presets'));
        $this->assertSame(['trainee', 'trainer', 'school', 'admin'], array_column($show->json('data.groups'), 'key'));
    }

    public function test_a_look_assigned_to_a_group_reaches_only_that_group_with_the_administrators_colours(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/app-themes', [
            'assignments' => ['trainee' => 'care', 'trainer' => 'learn'],
            'colors' => ['care' => ['primary' => '#112233', 'accent' => '#F2B33D']],
        ])->assertOk();
        Cache::flush();

        $employee = $this->makeEmployee();
        $mine = $this->asUser($employee->user)->getJson('/api/v1/me/app-theme')->assertOk()->json('data');
        $this->assertSame('care', $mine['id']);
        $this->assertSame('#112233', $mine['colors']['primary']);                 // the administrator's change
        $this->assertSame('#F2B33D', $mine['colors']['accent']);                  // equal to the look's own: not stored as a change
        $this->assertSame('flat', $mine['header']);
        $this->assertSame('stats', $mine['home'][0]);

        $trainer = $this->makeUser(Role::TRAINER);
        $this->asUser($trainer)->getJson('/api/v1/me/app-theme')->assertOk()->assertJsonPath('data.id', 'learn');
        $this->asUser($admin)->getJson('/api/v1/me/app-theme')->assertOk()->assertJsonPath('data', null);   // no look for the admin group
    }

    public function test_the_look_does_not_override_an_order_set_for_the_whole_app(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/app-themes', ['assignments' => ['trainee' => 'care'], 'colors' => []])->assertOk();
        $this->asUser($admin)->putJson('/api/v1/admin/app-home', ['sections' => [['key' => 'todo', 'enabled' => true]]])->assertOk();
        Cache::flush();

        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/me/app-theme')->assertOk()->assertJsonPath('data.home', null);
    }

    public function test_only_settings_managers_change_it_and_bad_input_is_refused(): void
    {
        $body = ['assignments' => ['trainee' => 'care'], 'colors' => []];
        $this->asUser($this->makeUser())->putJson('/api/v1/admin/app-themes', $body)->assertForbidden();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        foreach ([
            ['assignments' => ['trainee' => 'nope'], 'colors' => []],
            ['assignments' => ['martians' => 'care'], 'colors' => []],
            ['assignments' => [], 'colors' => ['care' => ['primary' => 'red']]],
            ['assignments' => [], 'colors' => ['nope' => ['primary' => '#112233']]],
        ] as $bad) {
            $this->asUser($admin)->putJson('/api/v1/admin/app-themes', $bad)->assertStatus(422);
        }
        $this->asUser($admin)->deleteJson('/api/v1/admin/app-themes')->assertOk();
    }
}
