<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Integrations\Teams\TeamsActivityFeed;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\NotificationChannels;
use App\Services\NotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonSchema\Validator;
use Tests\TestCase;
use ZipArchive;

/**
 * TEC-12: TEDC notifications in the Teams activity feed (Graph sendActivityNotification, application permission
 * TeamsActivity.Send) for people with a known Microsoft identity, with a deep link back to TEDC; the channel follows the
 * notification rules like push, e-mail and SMS, and its failures never hold the others back. The Teams app package the
 * Ministry's Teams administrator uploads is generated and checked against Microsoft's manifest schema.
 */
class TeamsActivityFeedTest extends TestCase
{
    private const APP_ID = '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        Cache::flush();
        app(IntegrationManager::class)->update('teams', ['driver' => 'graph', 'enabled' => true, 'settings' => [
            'tenant_id' => 'tenant-1', 'client_id' => '0a1b2c3d-1111-4222-8333-444455556666', 'client_secret' => 'sec', 'organizer_upn' => 'tedc-events@moe.gov.qa',
            'activity_feed' => true, 'teams_app_id' => self::APP_ID,
        ]]);
        app(ChannelSettings::class)->update(['email' => ['enabled' => true, 'driver' => 'log'], 'sms' => ['enabled' => false]]);
    }

    private function jwt(array $claims): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64('{"alg":"none","typ":"JWT"}').'.'.$b64(json_encode($claims)).'.sig';
    }

    private function graph(int $feedStatus = 204): void
    {
        Http::fake([
            'login.microsoftonline.com/tenant-1/oauth2/v2.0/token' => Http::response(['access_token' => $this->jwt(['roles' => ['TeamsActivity.Send', 'OnlineMeetings.ReadWrite.All']]), 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/teamwork/sendActivityNotification' => Http::response($feedStatus === 204 ? '' : ['error' => ['message' => 'Teams is unavailable']], $feedStatus),
        ]);
    }

    private function staff(?string $oid, string $locale = 'ar'): User
    {
        $u = $this->makeUser(Role::EMPLOYEE, ['locale' => $locale]);
        if ($oid) {
            UserIdentity::create(['user_id' => $u->id, 'provider' => 'entra', 'subject' => $oid, 'upn' => $u->email]);
        }

        return $u;
    }

    private function notify(User $u, string $type = 'session.reminder', array $data = []): void
    {
        app(NotificationService::class)->send($u, $type, ['ar' => 'تذكير: جلسة الغد', 'en' => 'Reminder: tomorrow\'s session'], ['ar' => 'تبدأ الجلسة الساعة 9', 'en' => 'It starts at 9'], $data + ['link' => '/portal/training']);
    }

    private function delivery(User $u, string $channel): ?NotificationDelivery
    {
        return NotificationDelivery::where('user_id', $u->id)->where('channel', $channel)->first();
    }

    public function test_a_notification_lands_in_the_activity_feed_with_a_deep_link_back_to_tedc(): void
    {
        $this->graph();
        $u = $this->staff('oid-1');
        $this->notify($u);

        $this->assertSame('sent', $this->delivery($u, 'teams')?->status);
        $this->assertSame('sent', $this->delivery($u, 'email')?->status);
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/users/oid-1/teamwork/sendActivityNotification')) {
                return false;
            }
            parse_str((string) parse_url($r['topic']['webUrl'], PHP_URL_QUERY), $q);

            return $r['topic']['source'] === 'text' && $r['topic']['value'] === 'تذكير: جلسة الغد' && $r['activityType'] === 'sessionUpdate'
                && str_starts_with($r['topic']['webUrl'], 'https://teams.microsoft.com/l/entity/'.self::APP_ID.'/tedc?')
                && $q['webUrl'] === rtrim((string) config('tedc.web_url'), '/').'/portal/training' && json_decode($q['context'], true) === ['subEntityId' => '/portal/training']
                && $r['previewText']['content'] === 'تبدأ الجلسة الساعة 9' && $r['templateParameters'] === [['name' => 'title', 'value' => 'تذكير: جلسة الغد']]
                && $r->hasHeader('Authorization');
        });
        // Every activity type TEDC sends is declared in the app manifest.
        $declared = array_column(app(TeamsActivityFeed::class)->manifest()['activities']['activityTypes'], 'type');
        $this->assertContains('sessionUpdate', $declared);
        $this->assertSame([], array_diff(array_values(TeamsActivityFeed::TYPES), $declared));
    }

    public function test_the_channel_follows_the_notification_rules(): void
    {
        $this->graph();
        $u = $this->staff('oid-2');
        NotificationRule::create(['event' => 'session.reminder', 'enabled' => true, 'channels' => ['teams'], 'priority' => 1]);
        $this->notify($u);
        $this->assertSame('sent', $this->delivery($u, 'teams')?->status);
        $this->assertNull($this->delivery($u, 'email'), 'the rule keeps this event to Teams');

        NotificationRule::query()->delete();
        NotificationRule::create(['event' => 'certificate.issued', 'enabled' => true, 'channels' => ['email'], 'priority' => 1]);
        $v = $this->staff('oid-3');
        $this->notify($v, 'certificate.issued');
        $this->assertNull($this->delivery($v, 'teams'));
        $this->assertSame('sent', $this->delivery($v, 'email')?->status);

        // A person can switch Teams off for a kind of notification, like any other channel — except the mandatory ones (the session reminder).
        $this->asUser($v)->putJson('/api/v1/me/notification-preferences', ['groups' => [['group' => 'session', 'channels' => ['push' => true, 'email' => true, 'sms' => true, 'teams' => false]]]])->assertOk();
        NotificationRule::query()->delete();
        $this->notify($v, 'session.attendance_open');
        $this->assertNull(NotificationDelivery::where('user_id', $v->id)->where('channel', 'teams')->where('type', 'session.attendance_open')->first());
        $this->assertNotNull(NotificationDelivery::where('user_id', $v->id)->where('channel', 'email')->where('type', 'session.attendance_open')->first());
        $this->notify($v);   // session.reminder
        $this->assertNotNull(NotificationDelivery::where('user_id', $v->id)->where('channel', 'teams')->where('type', 'session.reminder')->first(), 'mandatory notifications ignore the opt-out');
    }

    public function test_a_failing_feed_is_retried_and_never_holds_back_the_other_channels(): void
    {
        $this->graph(503);
        $u = $this->staff('oid-4');
        $this->notify($u);

        $this->assertSame('sent', $this->delivery($u, 'email')?->status, 'e-mail left at once');
        $row = $this->delivery($u, 'teams');
        $this->assertSame(['queued', 1], [$row->status, $row->attempts], 'kept for another try');
        app(NotificationChannels::class)->process();
        app(NotificationChannels::class)->process();
        $this->assertSame(['failed', 3], [$row->fresh()->status, $row->fresh()->attempts]);
        $this->assertStringContainsString('unavailable', (string) $row->fresh()->reason);
    }

    public function test_people_without_a_microsoft_identity_are_skipped_and_nothing_is_sent_when_the_feed_is_off(): void
    {
        $this->graph();
        $u = $this->staff(null);
        $this->notify($u);
        $this->assertSame(['skipped', 'no_teams_account'], [$this->delivery($u, 'teams')?->status, $this->delivery($u, 'teams')?->reason]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sendActivityNotification'));

        app(IntegrationManager::class)->update('teams', ['settings' => ['activity_feed' => false]]);
        $v = $this->staff('oid-5');
        $this->notify($v);
        $this->assertNull($this->delivery($v, 'teams'));
        $this->assertFalse(app(NotificationChannels::class)->status()['teams']['on']);
    }

    public function test_the_teams_app_package_validates_against_the_manifest_schema(): void
    {
        $zipPath = sys_get_temp_dir().'/tedc-teams-app-'.bin2hex(random_bytes(4)).'.zip';
        $this->artisan('tedc:teams-app-package', ['--out' => $zipPath])->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->beforeApplicationDestroyed(fn () => @unlink($zipPath));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'));
        $this->assertNotNull($manifest);
        $validator = new Validator;
        $validator->validate($manifest, json_decode((string) file_get_contents(base_path('tests/Fixtures/teams/MicrosoftTeams.schema.v1.29.json'))));
        $this->assertTrue($validator->isValid(), json_encode($validator->getErrors(), JSON_PRETTY_PRINT));
        $ar = json_decode((string) $zip->getFromName('ar.json'));
        $validator = new Validator;
        $validator->validate($ar, json_decode((string) file_get_contents(base_path('tests/Fixtures/teams/MicrosoftTeams.Localization.schema.v1.29.json'))));
        $this->assertTrue($validator->isValid(), json_encode($validator->getErrors(), JSON_PRETTY_PRINT));

        $this->assertSame(self::APP_ID, $manifest->id, 'the deep links point at this app');
        $this->assertSame('0a1b2c3d-1111-4222-8333-444455556666', $manifest->webApplicationInfo->id);
        $this->assertSame('ar', $manifest->localizationInfo->additionalLanguages[0]->languageTag);
        $this->assertContains(parse_url((string) config('tedc.web_url'), PHP_URL_HOST), $manifest->validDomains);
        $this->assertStringStartsWith(url('/api/v1/teams/tab'), $manifest->staticTabs[0]->contentUrl);
        foreach (['color.png' => 192, 'outline.png' => 32] as $file => $size) {
            $img = imagecreatefromstring((string) $zip->getFromName($file));
            $this->assertSame([$size, $size], [imagesx($img), imagesy($img)], $file);
        }
        $outline = imagecreatefromstring((string) $zip->getFromName('outline.png'));
        $this->assertSame(127, (imagecolorat($outline, 0, 0) >> 24) & 0x7F, 'the outline icon has a transparent background');

        // Administrators download the same package from the Teams card.
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->get('/api/v1/admin/teams/app-package')->assertOk()->assertHeader('Content-Type', 'application/zip');
    }

    public function test_the_teams_tab_opens_tedc_and_may_only_be_framed_by_teams(): void
    {
        $r = $this->get('/api/v1/teams/tab?to='.urlencode('/portal/training').'&locale=ar')->assertOk();
        $csp = (string) $r->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('https://teams.microsoft.com', $csp);
        $this->assertStringNotContainsString('*;', $csp);
        $this->assertStringContainsString(rtrim((string) config('tedc.web_url'), '/').'/portal/training', $r->getContent());
        // Only TEDC's own pages: anything else opens the TEDC home page.
        $this->assertStringNotContainsString('evil.example', $this->get('/api/v1/teams/tab?to='.urlencode('https://evil.example/x'))->assertOk()->getContent());
        $this->assertStringNotContainsString('evil.example', $this->get('/api/v1/teams/tab?to='.urlencode('//evil.example/x'))->assertOk()->getContent());
    }

    public function test_the_permissions_checklist_reads_what_the_tenant_granted(): void
    {
        $this->graph();
        $res = $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/teams/overview')->assertOk();
        $perms = collect($res->json('data.permissions'))->keyBy('permission');
        $this->assertTrue($perms['TeamsActivity.Send']['granted']);
        $this->assertTrue($perms['OnlineMeetings.ReadWrite.All']['granted']);
        $this->assertFalse($perms['Team.Create']['granted']);
        $this->assertSame('application', $perms['Team.Create']['type']);
        $this->assertSame('delegated', $perms['VirtualEvent.ReadWrite']['type']);
        $this->assertSame(self::APP_ID, $res->json('data.activity_feed.app_id'));
        $this->assertTrue($res->json('data.activity_feed.enabled'));
    }
}
