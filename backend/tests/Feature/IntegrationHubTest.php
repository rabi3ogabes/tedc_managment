<?php

namespace Tests\Feature;

use App\Integrations\EventBus;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\IntegrationUnavailable;
use App\Integrations\WebhookSignature;
use App\Models\AppNotification;
use App\Models\InboundEvent;
use App\Models\Integration;
use App\Models\IntegrationLog;
use App\Models\OutboxEvent;
use App\Models\Registration;
use App\Models\Role;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
    }

    private function admin()
    {
        return $this->makeUser(Role::CENTER_ADMIN);
    }

    public function test_settings_are_kept_encrypted_and_secrets_never_come_back(): void
    {
        $admin = $this->admin();
        $r = $this->asUser($admin)->putJson('/api/v1/admin/integrations/saaed', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => 'https://saaed.test', 'api_key' => 'super-secret-key', 'bogus' => 'x']])->assertOk();
        $this->assertSame('https://saaed.test', $r->json('data.settings.base_url'));
        $this->assertArrayNotHasKey('api_key', $r->json('data.settings'));
        $this->assertTrue($r->json('data.secrets_set.api_key'));
        $this->assertStringNotContainsString('super-secret-key', (string) Integration::find('saaed')->config);       // stored encrypted
        $this->assertSame('super-secret-key', Integration::find('saaed')->settings()['api_key']);

        // A blank secret keeps the stored one; "clear" removes it; unknown fields are ignored.
        $this->asUser($admin)->putJson('/api/v1/admin/integrations/saaed', ['settings' => ['base_url' => 'https://saaed2.test', 'api_key' => '']])->assertOk();
        $this->assertSame('super-secret-key', Integration::find('saaed')->settings()['api_key']);
        $this->assertArrayNotHasKey('bogus', Integration::find('saaed')->settings());
        $this->asUser($admin)->putJson('/api/v1/admin/integrations/saaed', ['clear' => ['api_key']])->assertOk();
        $this->assertArrayNotHasKey('api_key', Integration::find('saaed')->settings());

        $this->asUser($admin)->putJson('/api/v1/admin/integrations/saaed', ['driver' => 'telepathy'])->assertStatus(422);
        $this->asUser($admin)->getJson('/api/v1/admin/integrations')->assertOk()->assertJsonCount(count(IntegrationRegistry::all()), 'data');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/integrations')->assertForbidden();
        $this->asUser($admin)->putJson('/api/v1/admin/integrations/nope', [])->assertNotFound();
    }

    public function test_health_checks_and_the_circuit_breaker(): void
    {
        $admin = $this->admin();
        $hub = app(IntegrationManager::class);
        $this->asUser($admin)->putJson('/api/v1/admin/integrations/sijil', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => 'https://sijil.test']])->assertOk();

        Http::fake(['sijil.test/health' => Http::sequence()->push('ok', 200)->push('down', 503)]);
        $this->assertSame('ok', $this->asUser($admin)->postJson('/api/v1/admin/integrations/sijil/check')->assertOk()->json('data.health'));
        $this->assertSame('down', $this->asUser($admin)->postJson('/api/v1/admin/integrations/sijil/check')->json('data.health'));

        // Five failed calls in a row open the breaker, tell the administrators once, and stop further calls.
        $calls = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $hub->call('sijil', 'archive', function () use (&$calls) {
                    $calls++;
                    throw new \RuntimeException('boom');
                }, ['id' => 'x', 'api_key' => 'leaky']);
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(10, $calls);                                                      // each call was retried once
        $this->assertSame('down', Integration::find('sijil')->health);
        $this->assertTrue(Integration::find('sijil')->open_until->isFuture());
        $this->expectException(IntegrationUnavailable::class);
        try {
            $hub->call('sijil', 'archive', fn () => 'never');
        } finally {
            $this->assertSame(10, $calls);                                                  // not attempted while open
            $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'integration.down')->count());
            $this->assertSame('•••', IntegrationLog::where('integration_key', 'sijil')->where('operation', 'archive')->first()->request_summary['api_key']);   // no secrets in logs
        }
    }

    public function test_a_switched_off_system_is_not_called_and_a_successful_call_is_logged(): void
    {
        $hub = app(IntegrationManager::class);
        $this->expectException(IntegrationUnavailable::class);
        try {
            $hub->call('nsis', 'read', fn () => 1);
        } finally {
            $hub->update('nsis', ['driver' => 'fake', 'enabled' => true]);
            $this->assertSame(['ok' => true], $hub->call('nsis', 'read', fn () => ['ok' => true], ['school' => 'S1']));
            $this->assertSame('ok', IntegrationLog::where('integration_key', 'nsis')->latest('created_at')->first()->status);
            $this->assertSame('ok', Integration::find('nsis')->health);
        }
    }

    public function test_domain_events_are_delivered_as_signed_webhooks_with_retries_and_replay(): void
    {
        $admin = $this->admin();
        $employee = $this->makeEmployee();
        $program = $this->makeProgram();
        $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_PENDING]);

        // Nobody subscribed: nothing is written.
        $reg->update(['status' => Registration::STATUS_APPROVED]);
        $this->assertSame(0, OutboxEvent::count());

        $res = $this->asUser($admin)->postJson('/api/v1/admin/webhooks', ['name' => 'HR', 'url' => 'https://hooks.test/in', 'events' => ['registration.completed', 'certificate.issued']])->assertCreated();
        $secret = $res->json('data.secret');
        $sub = $res->json('data.id');
        $this->asUser($admin)->getJson('/api/v1/admin/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->asUser($admin)->postJson('/api/v1/admin/webhooks', ['name' => 'x', 'url' => 'http://insecure.test', 'events' => ['*']])->assertStatus(422);   // https only
        $this->asUser($admin)->postJson('/api/v1/admin/webhooks', ['name' => 'x', 'url' => 'https://x.test', 'events' => ['nope']])->assertStatus(422);

        $reg->update(['status' => Registration::STATUS_COMPLETED]);
        $this->assertSame(1, WebhookDelivery::where('subscription_id', $sub)->count());
        $this->assertSame('registration.completed', OutboxEvent::first()->type);
        $this->assertSame(0, OutboxEvent::where('type', 'registration.approved')->count());                 // not subscribed to that one

        $seen = [];
        $mode = 'flaky';
        Http::fake(['hooks.test/*' => function ($request) use (&$seen, &$mode) {
            $seen[] = ['headers' => $request->headers(), 'body' => $request->body()];

            return match ($mode) {
                'flaky' => count($seen) < 3 ? Http::response('busy', 503) : Http::response('ok', 200), 'down' => Http::response('no', 500), default => Http::response('ok', 200)
            };
        }]);
        $bus = app(EventBus::class);
        $this->assertSame(['delivered' => 0, 'retried' => 1, 'dead' => 0], $bus->deliverDue());
        $d = WebhookDelivery::first();
        $this->assertSame(1, $d->attempts);
        $this->assertTrue($d->next_attempt_at->isFuture());
        $this->assertSame(['delivered' => 0, 'retried' => 0, 'dead' => 0], $bus->deliverDue());              // not before its time

        // The receiver can verify what it got.
        $h = $seen[0]['headers'];
        $this->assertTrue(WebhookSignature::verify($secret, $h['X-TEDC-Timestamp'][0], $h['X-TEDC-Signature'][0], $seen[0]['body']));
        $this->assertFalse(WebhookSignature::verify('wrong', $h['X-TEDC-Timestamp'][0], $h['X-TEDC-Signature'][0], $seen[0]['body']));
        $this->assertFalse(WebhookSignature::verify($secret, (string) (time() - 3600), $h['X-TEDC-Signature'][0], $seen[0]['body']));   // too old
        $body = json_decode($seen[0]['body'], true);
        $this->assertSame('registration.completed', $body['type']);
        $this->assertSame($reg->id, $body['data']['registration_id']);

        WebhookDelivery::query()->update(['next_attempt_at' => now()->subMinute()]);
        $bus->deliverDue();
        WebhookDelivery::query()->update(['next_attempt_at' => now()->subMinute()]);
        $this->assertSame(1, $bus->deliverDue()['delivered']);
        $this->assertSame('delivered', $d->fresh()->status);

        // An endpoint that never answers is given up on, parked as dead, and can be replayed.
        $mode = 'down';
        $reg->update(['status' => Registration::STATUS_APPROVED]);
        $reg->update(['status' => Registration::STATUS_COMPLETED]);
        $dead = WebhookDelivery::where('status', 'pending')->first();
        for ($i = 0; $i < count(EventBus::BACKOFF) + 1; $i++) {
            WebhookDelivery::where('status', 'pending')->update(['next_attempt_at' => now()->subMinute()]);
            $bus->deliverDue();
        }
        $this->assertSame('dead', $dead->fresh()->status);
        $mode = 'ok';
        $this->asUser($admin)->postJson("/api/v1/admin/webhook-deliveries/{$dead->id}/replay")->assertOk();
        $this->assertSame(1, $bus->deliverDue()['delivered']);
        $this->asUser($admin)->getJson('/api/v1/admin/webhook-deliveries?status=delivered')->assertOk();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/webhooks')->assertForbidden();
        $this->assertInstanceOf(WebhookSubscription::class, WebhookSubscription::find($sub));
    }

    public function test_inbound_messages_need_a_valid_signature_and_are_applied_once(): void
    {
        $hub = app(IntegrationManager::class);
        $hub->update('nsis', ['driver' => 'fake', 'enabled' => true, 'settings' => ['inbound_secret' => 'shared-1']]);
        $body = json_encode(['id' => 'evt-1', 'type' => 'something']);
        $send = function (string $b, ?string $secret = 'shared-1', ?int $at = null, array $headers = []) {
            $ts = (string) ($at ?? time());
            $sig = $secret ? 'sha256='.WebhookSignature::sign($secret, $ts, $b) : 'sha256=bad';
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TEDC_TIMESTAMP' => $ts, 'HTTP_X_TEDC_SIGNATURE' => $sig];
            foreach ($headers as $k => $v) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
            }

            return $this->call('POST', '/api/v1/integrations/nsis/inbound', [], [], [], $server, $b);
        };

        $send($body, null)->assertStatus(401);
        $send($body, 'other-secret')->assertStatus(401);
        $send($body, 'shared-1', time() - 3600)->assertStatus(401);                      // replayed old request
        $send($body)->assertOk()->assertJsonPath('data.status', 'ignored');              // nothing in TEDC acts on it, but it is accepted
        $send($body)->assertOk()->assertJsonPath('data.status', 'duplicate');            // the same id is not applied twice
        $send('not json')->assertStatus(422);
        $send(json_encode(['type' => 'no id']))->assertStatus(422);                      // an idempotency key is required
        $send(json_encode(['type' => 'x']), 'shared-1', null, ['Idempotency-Key' => 'k-9'])->assertOk();
        $this->assertSame(2, InboundEvent::count());
        $this->assertGreaterThanOrEqual(3, IntegrationLog::where('integration_key', 'nsis')->where('direction', 'in')->count());
        $this->postJson('/api/v1/integrations/ghost/inbound')->assertNotFound();
    }
}
