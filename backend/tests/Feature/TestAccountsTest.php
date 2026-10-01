<?php

namespace Tests\Feature;

use App\Models\JobTitle;
use App\Models\Role;
use Tests\TestCase;

class TestAccountsTest extends TestCase
{
    public function test_test_accounts_are_created_assigned_and_described_for_the_dashboard(): void
    {
        $this->makeEmployee()->user->update(['email' => 'teacher@tedc.qa']);
        JobTitle::firstOrCreate(['code' => 'TEACHER'], ['name_ar' => 'معلم', 'name_en' => 'Teacher']);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($admin)->getJson('/api/v1/admin/test-accounts')->assertOk()->assertJsonPath('data.seeded', false);
        $this->asUser($admin)->postJson('/api/v1/admin/test-accounts')->assertOk()->assertJsonPath('data.seeded', true);
        $data = $this->asUser($admin)->postJson('/api/v1/admin/test-accounts')->assertOk()->json('data'); // idempotent

        $accounts = collect($data['accounts']);
        $this->assertCount(6, $accounts);
        $this->assertSame([1], $accounts->firstWhere('email', 'trainee1@tedc.qa')['programs']);
        $this->assertSame([1, 2, 3], $accounts->firstWhere('email', 'trainee3@tedc.qa')['programs']);
        $this->assertSame([1, 2, 3, 4], $accounts->firstWhere('email', 'trainee4@tedc.qa')['programs']);
        $this->assertSame([1, 2], $accounts->firstWhere('email', 'trainer1@tedc.qa')['programs']);
        $this->assertSame([3, 4], $accounts->firstWhere('email', 'trainer2@tedc.qa')['programs']);
        $this->assertSame([4, 3, 2, 1], collect($data['programs'])->pluck('trainees')->all());
        $this->assertSame(4, collect($data['programs'])->count());

        // One tap on the app's login screen signs in with these accounts.
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'trainee2@tedc.qa', 'password' => 'Tedc@2026!'])->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/me/home')->assertOk();

        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/test-accounts')->assertForbidden();
    }
}
