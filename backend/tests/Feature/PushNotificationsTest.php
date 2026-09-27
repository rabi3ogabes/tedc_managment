<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Services\NotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Firebase push notifications managed from Settings → Notifications. */
class PushNotificationsTest extends TestCase
{
    private const FCM = 'https://fcm.googleapis.com/v1/projects/tedc-demo/messages:send';

    private string $serviceAccount;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem = '';
        openssl_pkey_export($key, $pem);
        $this->serviceAccount = json_encode([
            'type' => 'service_account', 'project_id' => 'tedc-demo', 'private_key_id' => 'abcdef1234567890',
            'private_key' => $pem, 'client_email' => 'push@tedc-demo.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    private function configure(array $overrides = []): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/push', $overrides + [
            'enabled' => true,
            'service_account_json' => $this->serviceAccount,
            'client' => ['api_key' => 'AIzaSyTest_key-123', 'app_id' => '1:123456789:android:abc123', 'messaging_sender_id' => '123456789'],
        ])->assertOk();
    }

    private function fakeGoogle(array $fcmResponses = []): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            self::FCM => $fcmResponses ? Http::sequence($fcmResponses) : Http::response(['name' => 'projects/tedc-demo/messages/1']),
        ]);
    }

    public function test_settings_never_expose_the_private_key(): void
    {
        $this->configure();
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $response = $this->asUser($admin)->getJson('/api/v1/admin/settings/push')->assertOk()
            ->assertJsonPath('data.settings.ready', true)
            ->assertJsonPath('data.settings.service_account.client_email', 'push@tedc-demo.iam.gserviceaccount.com')
            ->assertJsonPath('data.settings.client.project_id', 'tedc-demo');

        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $audit = AuditLog::where('auditable_type', (new SiteSetting)->getMorphClass())->latest()->first();
        $this->assertSame('[redacted]', $audit->new_values['value']['service_account'] ?? null);
    }

    public function test_invalid_service_account_is_rejected(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $this->asUser($admin)->putJson('/api/v1/admin/settings/push', ['service_account_json' => '{"type":"authorized_user"}'])
            ->assertStatus(422)->assertJsonValidationErrors('service_account_json');
    }

    public function test_only_settings_managers_can_change_push_settings(): void
    {
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/settings/push')->assertForbidden();
    }

    public function test_mobile_config_publishes_client_options_only_when_ready(): void
    {
        $this->getJson('/api/v1/public/mobile-config')->assertOk()->assertJsonPath('data.push.enabled', false)->assertJsonPath('data.push.options', null);

        $this->configure();

        $this->getJson('/api/v1/public/mobile-config')->assertOk()
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonPath('data.push.options.app_id', '1:123456789:android:abc123')
            ->assertJsonPath('data.brand.primary', '#8A1538');
    }

    public function test_notifications_are_pushed_in_each_device_language_and_dead_tokens_pruned(): void
    {
        $this->configure();
        $this->fakeGoogle([
            Http::response(['name' => 'ok']),
            Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $user = $this->makeUser(Role::EMPLOYEE);
        $this->asUser($user)->postJson('/api/v1/me/devices', ['token' => str_repeat('a', 40), 'platform' => 'android', 'locale' => 'en'])->assertCreated();
        $this->asUser($user)->postJson('/api/v1/me/devices', ['token' => str_repeat('b', 40), 'platform' => 'android', 'locale' => 'ar'])->assertCreated();

        app(NotificationService::class)->send($user, 'certificate.issued', ['ar' => 'تم إصدار شهادتك', 'en' => 'Your certificate is ready'], ['ar' => 'مبروك', 'en' => 'Congratulations']);

        Http::assertSent(fn (Request $r) => $r->url() === self::FCM && $r['message']['token'] === str_repeat('a', 40)
            && $r['message']['notification']['title'] === 'Your certificate is ready' && $r->hasHeader('Authorization', 'Bearer ya29.test'));
        Http::assertSent(fn (Request $r) => $r->url() === self::FCM && $r['message']['notification']['title'] === 'تم إصدار شهادتك'
            && $r['message']['data']['type'] === 'certificate.issued' && $r['message']['android']['notification']['channel_id'] === 'tedc_general');

        $this->assertDatabaseHas('push_logs', ['type' => 'certificate.issued', 'devices' => 2, 'delivered' => 1, 'failed' => 1, 'pruned' => 1]);
        $this->assertSame(1, DeviceToken::count());
    }

    public function test_disabled_categories_are_not_pushed(): void
    {
        $this->configure(['categories' => ['announcement' => false]]);
        $this->fakeGoogle();
        $user = $this->makeUser(Role::EMPLOYEE);
        DeviceToken::create(['user_id' => $user->id, 'token' => str_repeat('c', 40), 'platform' => 'android']);

        app(NotificationService::class)->broadcast([$user->id], 'announcement', ['ar' => 'إعلان', 'en' => 'News']);

        Http::assertNotSent(fn (Request $r) => $r->url() === self::FCM);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'announcement']);
    }

    public function test_admin_can_verify_and_send_a_test_to_own_devices(): void
    {
        $this->configure();
        $this->fakeGoogle();
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $this->asUser($admin)->postJson('/api/v1/admin/settings/push/verify')->assertOk()->assertJsonPath('data.project_id', 'tedc-demo');
        $this->asUser($admin)->postJson('/api/v1/admin/settings/push/test')->assertStatus(422)->assertJsonValidationErrors('audience');

        DeviceToken::create(['user_id' => $admin->id, 'token' => str_repeat('d', 40), 'platform' => 'android']);
        $this->asUser($admin)->postJson('/api/v1/admin/settings/push/test')->assertOk()->assertJsonPath('data.delivered', 1);
    }

    public function test_device_token_moves_to_the_account_that_signs_in(): void
    {
        $first = $this->makeUser(Role::EMPLOYEE);
        $second = $this->makeUser(Role::EMPLOYEE);
        $token = str_repeat('e', 40);

        $this->asUser($first)->postJson('/api/v1/me/devices', ['token' => $token, 'platform' => 'android'])->assertCreated();
        $this->asUser($second)->postJson('/api/v1/me/devices', ['token' => $token, 'platform' => 'android'])->assertCreated();
        $this->assertSame($second->id, DeviceToken::where('token', $token)->value('user_id'));

        $this->asUser($second)->deleteJson('/api/v1/me/devices', ['token' => $token])->assertNoContent();
        $this->assertSame(0, DeviceToken::count());
    }
}
