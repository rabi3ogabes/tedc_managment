<?php

namespace Tests\Feature;

use App\Models\PresenceSession;
use App\Models\Role;
use App\Services\SecuritySettings;
use Tests\TestCase;

class PresenceAndLockTest extends TestCase
{
    private function beat($user, array $data = [])
    {
        return $this->asUser($user)->postJson('/api/v1/me/presence', $data + ['platform' => 'web', 'path' => '/admin']);
    }

    public function test_heartbeats_build_sessions_and_the_live_view_splits_team_and_app_users(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $member = $this->makeEmployee()->user;

        $this->beat($admin, ['path' => '/admin/programs'])->assertOk();
        $this->beat($admin, ['path' => '/admin/rooms'])->assertOk();
        $this->beat($member, ['platform' => 'mobile', 'path' => '/app/home'])->assertOk();

        $this->assertSame(2, PresenceSession::count(), 'consecutive heartbeats extend one session');
        $this->assertSame(2, PresenceSession::where('user_id', $admin->id)->value('hits'));

        $live = $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk()->json('data');
        $this->assertSame(2, $live['online']['total']);
        $this->assertSame(1, $live['online']['staff']);
        $this->assertSame(1, $live['online']['members']);
        $this->assertSame(1, $live['online']['mobile']);
        $this->assertCount(30, $live['timeline']);
        $this->assertContains('/admin/rooms', array_column($live['top_pages'], 'path'));

        // Someone who left long ago is not online any more, and a new visit starts a new session.
        $this->travel(12)->minutes();
        $this->beat($member, ['platform' => 'mobile'])->assertOk();
        $this->assertSame(3, PresenceSession::count());
        $this->assertSame(1, $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/presence/live')->json('data.online.total'));
    }

    public function test_todays_peak_covers_the_whole_day_not_only_the_last_minutes(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->travelTo(now()->startOfDay()->setHour(14));
        PresenceSession::create(['user_id' => $admin->id, 'platform' => 'web', 'team' => 'staff', 'started_at' => now()->subHours(5), 'last_seen_at' => now()->subHours(4)->subMinutes(30)]);
        $this->beat($admin)->assertOk();

        $this->assertGreaterThanOrEqual(1, $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->json('data.today.peak'));
        $this->assertSame(2, $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->json('data.today.sessions'));
    }

    public function test_report_summary_and_csv_export_are_restricted_to_analytics_viewers(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $member = $this->makeEmployee()->user;
        $this->beat($admin)->assertOk();

        $this->asUser($member)->getJson('/api/v1/admin/presence/live')->assertForbidden();
        $summary = $this->asUser($admin)->getJson('/api/v1/admin/presence/report')->assertOk()->json('data.summary');
        $this->assertSame(1, $summary['unique_users']);
        $csv = $this->asUser($admin)->get('/api/v1/admin/presence/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFName,Email,Team", $csv);
        $this->assertStringContainsString($admin->email, $csv);
    }

    public function test_idle_administrators_are_locked_until_they_confirm_their_password(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->beat($admin)->assertOk();
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk();

        $this->travel(11)->minutes();
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertStatus(423)->assertJsonPath('code', 'session_locked');
        // Own-profile and heartbeat calls keep working so the lock screen can be shown.
        $this->asUser($admin)->getJson('/api/v1/auth/me')->assertOk();
        $this->beat($admin, ['idle_seconds' => 600])->assertOk()->assertJsonPath('data.locked', true);

        $this->asUser($admin)->postJson('/api/v1/auth/unlock', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertStatus(423);
        $this->asUser($admin)->postJson('/api/v1/auth/unlock', ['password' => 'Secret#12345'])->assertOk();
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk();
    }

    public function test_real_activity_keeps_the_session_open_and_the_lock_can_be_switched_off_or_tuned(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->beat($admin)->assertOk();

        $this->travel(9)->minutes();
        $this->beat($admin, ['idle_seconds' => 5])->assertOk(); // the user is working
        $this->travel(9)->minutes();
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk();

        $this->asUser($admin)->putJson('/api/v1/admin/settings/security', ['idle_lock_minutes' => 2])->assertOk()->assertJsonPath('data.idle_lock_minutes', 2);
        $this->travel(3)->minutes();
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertStatus(423);

        $this->asUser($admin)->postJson('/api/v1/auth/unlock', ['password' => 'Secret#12345'])->assertOk();
        $this->asUser($admin)->putJson('/api/v1/admin/settings/security', ['idle_lock_enabled' => false])->assertOk();
        $this->travel(60)->minutes();
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk();
        $this->assertFalse(app(SecuritySettings::class)->lockEnabled());
    }

    public function test_app_users_are_never_locked_out_of_their_own_endpoints(): void
    {
        $member = $this->makeEmployee()->user;
        $this->beat($member, ['platform' => 'mobile'])->assertOk();
        $this->travel(60)->minutes();
        $this->asUser($member)->getJson('/api/v1/me/home')->assertOk();
    }

    public function test_admin_can_rename_menus_and_buttons_in_both_languages(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->getJson('/api/v1/public/labels')->assertOk()->assertJsonPath('data.ar', []);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/labels', ['ar' => ['admin.menu.programs' => 'برامج'], 'en' => ['admin.menu.programs' => 'Courses']])->assertOk();
        $public = $this->getJson('/api/v1/public/labels')->assertOk()->json();
        $this->assertSame('برامج', $public['data']['ar']['admin.menu.programs']);
        $this->assertSame('Courses', $public['data']['en']['admin.menu.programs']);

        // Empty value resets one label; unsafe input is cleaned; a bad key is ignored.
        $this->asUser($admin)->putJson('/api/v1/admin/settings/labels', ['ar' => ['admin.menu.programs' => '', 'nav.home' => '<b>الرئيسية</b>', 'bad key!' => 'x']])->assertOk();
        $labels = $this->asUser($admin)->getJson('/api/v1/admin/settings/labels')->json('data');
        $this->assertArrayNotHasKey('admin.menu.programs', $labels['ar']);
        $this->assertSame('الرئيسية', $labels['ar']['nav.home']);
        $this->assertArrayNotHasKey('bad key!', $labels['ar']);
        $this->assertSame('Courses', $labels['en']['admin.menu.programs']);

        $this->asUser($admin)->putJson('/api/v1/admin/settings/labels', ['replace' => true])->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/public/labels')->json('data.en'));
        $this->asUser($this->makeEmployee()->user)->putJson('/api/v1/admin/settings/labels', ['ar' => ['nav.home' => 'x']])->assertForbidden();
    }

    public function test_online_users_show_where_they_are_and_what_they_use_and_the_admin_can_switch_it_off(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $member = $this->makeEmployee()->user;
        $geo = ['x-vercel-ip-country' => 'QA', 'x-vercel-ip-city' => rawurlencode('Al Wakrah'), 'x-vercel-ip-latitude' => '25.1715', 'x-vercel-ip-longitude' => '51.6034'];

        $this->asUser($member)->withHeaders($geo + ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537'])->postJson('/api/v1/me/presence', ['platform' => 'web', 'path' => '/portal'])->assertOk();
        $this->asUser($admin)->withHeaders($geo + ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17) Safari/604'])->postJson('/api/v1/me/presence', ['platform' => 'web', 'path' => '/admin'])->assertOk();

        $live = $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk()->assertJsonPath('data.enabled', true)->json('data');
        $this->assertSame(1, $live['by_source']['desktop']);
        $this->assertSame(1, $live['by_source']['mobile_web']);
        $this->assertSame('Al Wakrah', $live['places'][0]['city']);
        $this->assertSame(2, $live['places'][0]['count']);
        $this->assertSame('QA', $live['by_country'][0]['country']);

        // Switched off: nothing new is recorded and the page is told so.
        $this->asUser($admin)->putJson('/api/v1/admin/presence/settings', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $other = $this->makeEmployee()->user;
        $this->asUser($other)->postJson('/api/v1/me/presence', ['platform' => 'mobile'])->assertOk();
        $this->assertSame(0, PresenceSession::where('user_id', $other->id)->count());
        $this->asUser($admin)->getJson('/api/v1/admin/presence/live')->assertOk()->assertJsonPath('data.enabled', false);
        $this->asUser($this->makeUser(Role::COORDINATOR))->putJson('/api/v1/admin/presence/settings', ['enabled' => true])->assertForbidden();
    }
}
