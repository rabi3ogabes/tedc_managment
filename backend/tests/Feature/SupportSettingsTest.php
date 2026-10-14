<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SiteSetting;
use Tests\TestCase;

/**
 * UTR-03: the centre's phone, e-mail, Saaed link, working hours and service levels are entered in Settings → Help & support, validated
 * and audited, and shown in the help centre, the app and the e-mail footers. The environment variables are only the defaults.
 */
class SupportSettingsTest extends TestCase
{
    private const URL = '/api/v1/admin/settings/support';

    private function admin()
    {
        return $this->makeUser(Role::CENTER_ADMIN);
    }

    private function valid(array $extra = []): array
    {
        return $extra + ['phone' => '+974 4000 1234', 'email' => 'support@tedc.example', 'saaed_url' => 'https://saaed.example/tedc', 'hours_ar' => 'الأحد – الخميس، 7:00 ص – 3:00 م', 'hours_en' => 'Sunday – Thursday, 7:00 – 15:00'];
    }

    public function test_the_defaults_come_from_the_environment_and_the_standard_service_levels(): void
    {
        config(['tedc.support' => ['phone' => '+974 4999 0000', 'email' => 'env@tedc.example', 'saaed_url' => '', 'hours_ar' => 'ساعات البيئة', 'hours_en' => 'Env hours']]);

        $r = $this->asUser($this->admin())->getJson(self::URL)->assertOk();

        $this->assertSame(['+974 4999 0000', 'env@tedc.example', '', 'Env hours'], [$r->json('data.phone'), $r->json('data.email'), $r->json('data.saaed_url'), $r->json('data.hours_en')]);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($r->json('data.sla'), 'priority'));
        $this->assertSame('15 minutes', $r->json('data.sla.0.response_en'));
        $this->assertFalse($r->json('data.saved'));
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson(self::URL)->assertForbidden();
    }

    public function test_saved_values_replace_the_defaults_and_an_emptied_field_stays_empty(): void
    {
        config(['tedc.support' => ['phone' => '+974 4999 0000', 'email' => 'env@tedc.example']]);
        $admin = $this->admin();

        $this->asUser($admin)->putJson(self::URL, $this->valid(['phone' => '']))->assertOk()->assertJsonPath('data.saved', true);

        $r = $this->asUser($admin)->getJson(self::URL)->assertOk();
        $this->assertSame(['', 'support@tedc.example', 'https://saaed.example/tedc'], [$r->json('data.phone'), $r->json('data.email'), $r->json('data.saaed_url')], 'the phone was emptied on purpose: the environment value does not come back');
        $this->assertNotNull(SiteSetting::find('support'));
    }

    public function test_the_values_are_validated(): void
    {
        $admin = $this->admin();
        foreach ([['phone' => 'call me maybe'], ['phone' => str_repeat('1', 40)], ['email' => 'not-an-email'], ['saaed_url' => 'http://insecure.example'], ['saaed_url' => 'javascript:alert(1)'], ['saaed_url' => 'saaed.example'], ['hours_ar' => str_repeat('x', 200)], ['hours_en' => str_repeat('x', 200)]] as $bad) {
            $this->asUser($admin)->putJson(self::URL, $this->valid($bad))->assertStatus(422);
        }
        $this->asUser($admin)->putJson(self::URL, $this->valid(['sla' => [['priority' => 'P9', 'response_ar' => 'x', 'response_en' => 'x', 'resolution_ar' => 'x', 'resolution_en' => 'x']]]))->assertStatus(422);
        $this->asUser($admin)->putJson(self::URL, $this->valid(['sla' => [['priority' => 'P1', 'response_ar' => str_repeat('x', 100), 'response_en' => 'x', 'resolution_ar' => 'x', 'resolution_en' => 'x']]]))->assertStatus(422);
        $this->assertNull(SiteSetting::find('support'), 'nothing invalid was saved');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson(self::URL, $this->valid())->assertForbidden();
    }

    public function test_the_service_levels_can_be_changed_and_must_cover_every_priority(): void
    {
        $admin = $this->admin();
        $levels = [
            ['priority' => 'P1', 'response_ar' => '10 دقائق', 'response_en' => '10 minutes', 'resolution_ar' => 'ساعة', 'resolution_en' => '1 hour'],
            ['priority' => 'P2', 'response_ar' => '30 دقيقة', 'response_en' => '30 minutes', 'resolution_ar' => '4 ساعات', 'resolution_en' => '4 hours'],
            ['priority' => 'P3', 'response_ar' => 'ساعتان', 'response_en' => '2 hours', 'resolution_ar' => 'يوم عمل', 'resolution_en' => '1 business day'],
            ['priority' => 'P4', 'response_ar' => '4 ساعات', 'response_en' => '4 hours', 'resolution_ar' => 'يوما عمل', 'resolution_en' => '2 business days'],
        ];

        $this->asUser($admin)->putJson(self::URL, $this->valid(['sla' => array_slice($levels, 0, 2)]))->assertStatus(422);
        $this->asUser($admin)->putJson(self::URL, $this->valid(['sla' => $levels]))->assertOk();

        $this->assertSame('10 minutes', $this->asUser($admin)->getJson(self::URL)->json('data.sla.0.response_en'));
    }

    public function test_every_change_is_audited_with_what_it_was_and_what_it_became(): void
    {
        $admin = $this->admin();
        $this->asUser($admin)->putJson(self::URL, $this->valid())->assertOk();
        $this->asUser($admin)->putJson(self::URL, $this->valid(['phone' => '+974 4000 9999']))->assertOk();

        $entries = AuditLog::where('action', 'settings.support.updated')->where('user_id', $admin->id)->orderBy('created_at')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('+974 4000 1234', $entries[1]->old_values['phone']);
        $this->assertSame('+974 4000 9999', $entries[1]->new_values['phone']);
    }

    public function test_the_help_centre_and_the_app_show_what_the_centre_entered(): void
    {
        $this->asUser($this->admin())->putJson(self::URL, $this->valid())->assertOk();

        $help = $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/me/help/articles')->assertOk();
        $channels = collect($help->json('support.channels'))->keyBy('key');

        $this->assertSame('+974 4000 1234', $channels['phone']['value']);
        $this->assertSame('support@tedc.example', $channels['email']['value']);
        $this->assertSame('https://saaed.example/tedc', $channels['saaed']['value']);
        $this->assertSame('Sunday – Thursday, 7:00 – 15:00', $channels['phone']['hours_en']);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($help->json('support.sla'), 'priority'));
    }

    public function test_the_e_mail_footer_carries_the_contacts_only_when_the_centre_has_entered_them(): void
    {
        $html = fn (string $lang) => (new NotificationMail('Title', 'Body', $lang))->render();

        $empty = $html('en');
        $this->assertStringNotContainsString('Need help?', $empty);

        $this->asUser($this->admin())->putJson(self::URL, $this->valid())->assertOk();
        $en = $html('en');
        $this->assertStringContainsString('Need help?', $en);
        $this->assertStringContainsString('+974 4000 1234', $en);
        $this->assertStringContainsString('support@tedc.example', $en);
        $this->assertStringContainsString('Sunday – Thursday, 7:00 – 15:00', $en);
        $ar = $html('ar');
        $this->assertStringContainsString('تحتاج مساعدة؟', $ar);
        $this->assertStringContainsString('الأحد – الخميس، 7:00 ص – 3:00 م', $ar);
    }
}
