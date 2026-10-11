<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AppHomeLayoutTest extends TestCase
{
    public function test_the_default_order_reaches_the_app_and_a_saved_order_replaces_it(): void
    {
        $default = $this->getJson('/api/v1/public/mobile-config')->assertOk()->json('data.home.sections');
        $this->assertSame(['hours', 'todo', 'stats', 'scan', 'needs', 'programs', 'dashboard', 'sessions', 'recommended'], $default);

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $res = $this->asUser($admin)->putJson('/api/v1/admin/app-home', ['sections' => [
            ['key' => 'programs', 'enabled' => true], ['key' => 'hours', 'enabled' => true], ['key' => 'recommended', 'enabled' => false],
        ]])->assertOk();
        // The cards the list leaves out come back at the end, so none is lost.
        $this->assertSame(['programs', 'hours', 'recommended', 'todo', 'stats', 'scan', 'needs', 'dashboard', 'sessions'], array_column($res->json('data'), 'key'));

        Cache::flush();
        $shown = $this->getJson('/api/v1/public/mobile-config')->assertOk()->json('data.home.sections');
        $this->assertSame(['programs', 'hours', 'todo', 'stats', 'scan', 'needs', 'dashboard', 'sessions'], $shown);

        $this->asUser($admin)->deleteJson('/api/v1/admin/app-home')->assertOk();
        Cache::flush();
        $this->assertSame($default, $this->getJson('/api/v1/public/mobile-config')->json('data.home.sections'));
    }

    public function test_only_settings_managers_change_it_and_unknown_cards_are_refused(): void
    {
        $body = ['sections' => [['key' => 'hours', 'enabled' => true]]];
        $this->asUser($this->makeUser())->putJson('/api/v1/admin/app-home', $body)->assertForbidden();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/app-home', ['sections' => [['key' => 'nope', 'enabled' => true]]])->assertStatus(422);
        $this->asUser($admin)->putJson('/api/v1/admin/app-home', ['sections' => [['key' => 'hours', 'enabled' => true], ['key' => 'hours', 'enabled' => false]]])->assertStatus(422);
    }
}
