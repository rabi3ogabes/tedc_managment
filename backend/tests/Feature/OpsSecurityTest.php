<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DataSubjectRequest;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\SiemOutbox;
use App\Models\User;
use App\Ops\Anonymiser;
use App\Ops\DataClassification;
use App\Ops\DataSubjectService;
use App\Security\SecurityEvents;
use App\Security\Siem\SiemForwarder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpsSecurityTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        SiemForwarder::$received = [];
    }

    private function siem(string $driver, array $settings = []): void
    {
        $this->asUser($this->admin)->putJson('/api/v1/admin/integrations/siem', ['driver' => $driver, 'enabled' => true, 'settings' => $settings])->assertOk();
        Cache::forget('siem.active');
    }

    // ---- health ------------------------------------------------------------------------------------

    public function test_live_and_ready_probes_answer_without_secrets_and_report_a_lagging_queue(): void
    {
        $this->getJson('/api/v1/public/health/live')->assertOk()->assertJsonPath('status', 'ok');
        $r = $this->getJson('/api/v1/public/health/ready')->assertOk();
        $this->assertSame('ok', $r->json('status'));
        foreach (['database', 'cache', 'storage', 'queue'] as $c) {
            $this->assertSame('ok', $r->json("checks.{$c}.status"), $c);
        }
        $this->assertStringNotContainsString(config('database.connections.sqlite.database') ?: 'x@', json_encode($r->json()));

        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 900, 'created_at' => time() - 900]);
        $d = $this->getJson('/api/v1/public/health/ready')->assertOk()->json();
        $this->assertSame('degraded', $d['status']);                          // still serves traffic, but says so
        $this->assertGreaterThan(600, $d['checks']['queue']['lag_seconds']);
    }

    public function test_a_failing_storage_service_is_named_in_the_ready_probe_without_its_address(): void
    {
        config(['tedc.storage_driver' => 'supabase', 'tedc.supabase.url' => 'https://project.example.supabase.co', 'tedc.supabase.service_key' => 'k', 'tedc.supabase.buckets.documents' => 'documents']);
        Cache::forget('health:storage-marker');
        Http::fake(['*' => Http::response(['message' => 'Bucket not found'], 404)]);
        $r = $this->getJson('/api/v1/public/health/ready')->assertStatus(503)->json();
        $this->assertSame('fail', $r['checks']['storage']['status']);
        $this->assertStringContainsString('404', $r['checks']['storage']['reason']);
        $this->assertStringContainsString('Bucket not found', $r['checks']['storage']['reason']);
        $this->assertStringNotContainsString('supabase.co', json_encode($r));
    }

    public function test_every_response_carries_a_request_id_that_a_caller_may_supply(): void
    {
        $a = $this->getJson('/api/v1/public/health/live')->assertOk()->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $a);
        $this->withHeader('X-Request-Id', 'gateway-req-12345')->getJson('/api/v1/public/health/live')->assertHeader('X-Request-Id', 'gateway-req-12345');
        $evil = $this->withHeader('X-Request-Id', "bad\nheader injection")->getJson('/api/v1/public/health/live')->headers->get('X-Request-Id');
        $this->assertStringNotContainsString("\n", (string) $evil);
    }

    // ---- SIEM --------------------------------------------------------------------------------------

    public function test_nothing_is_queued_while_no_siem_is_connected(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'wrong'])->assertStatus(422);
        $this->assertGreaterThan(0, SecurityEvent::where('type', 'login_failed')->count());     // kept locally anyway
        $this->assertSame(0, SiemOutbox::count());
    }

    public function test_security_events_and_audit_records_reach_the_siem_without_secrets(): void
    {
        $this->siem('fake');
        $emp = $this->makeUser();
        $this->postJson('/api/v1/auth/login', ['email' => $emp->email, 'password' => 'definitely-wrong'])->assertStatus(422);
        $this->asUser($emp)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->asUser($emp)->getJson('/api/v1/admin/users')->assertForbidden();                  // the same denial again within a minute is one event
        $this->postJson('/api/v1/auth/login', ['email' => $emp->email, 'password' => 'Secret#12345'])->assertOk();
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'role_changed', 'auditable_type' => User::class, 'auditable_id' => $emp->id, 'new_values' => ['password' => 'must-not-travel']]);

        $r = app(SiemForwarder::class)->flush();
        $this->assertGreaterThanOrEqual(3, $r['sent']);
        $types = collect(SiemForwarder::$received)->where('kind', 'security')->pluck('type')->all();
        $this->assertContains('login_failed', $types);
        $this->assertContains('login_success', $types);
        $this->assertSame(1, collect($types)->filter(fn ($t) => $t === 'permission_denied')->count());
        $this->assertContains('role_changed', collect(SiemForwarder::$received)->where('kind', 'audit')->pluck('action')->all());
        $all = json_encode(SiemForwarder::$received);
        foreach (['must-not-travel', 'definitely-wrong', 'Secret#12345'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertSame(0, SiemOutbox::whereNull('sent_at')->count());
    }

    public function test_splunk_hec_gets_batched_events_with_the_token_and_a_failure_is_retried(): void
    {
        $this->siem('splunk_hec', ['url' => 'https://splunk.test:8088', 'token' => 'hec-token', 'index' => 'tedc']);
        SecurityEvents::record('login_failed', null, 'failed', ['n' => 1]);
        SecurityEvents::record('mfa_failed', null, 'failed');
        Http::fake(['splunk.test:8088/*' => Http::sequence()->push('down', 503)->push('down', 503)->push(['text' => 'Success', 'code' => 0], 200)]);
        $first = app(SiemForwarder::class)->flush();
        $this->assertSame(['sent' => 0, 'failed' => 2], $first);
        $this->assertSame(2, SiemOutbox::whereNull('sent_at')->where('attempts', '>=', 1)->count());     // kept for the next try
        $this->assertSame(['sent' => 2, 'failed' => 0], app(SiemForwarder::class)->flush());
        Http::assertSent(function ($r) {
            $lines = array_filter(explode("\n", $r->body()));

            return $r->url() === 'https://splunk.test:8088/services/collector/event' && $r->header('Authorization')[0] === 'Splunk hec-token' && count($lines) === 2 && json_decode(reset($lines), true)['index'] === 'tedc' && json_decode(reset($lines), true)['sourcetype'] === '_json';
        });
        $this->assertSame(0, SiemOutbox::whereNull('sent_at')->count());
    }

    public function test_sentinel_gets_rows_through_the_logs_ingestion_api(): void
    {
        $this->siem('log_analytics', ['tenant_id' => 'tenant-1', 'client_id' => 'cid', 'client_secret' => 'csecret', 'dce_endpoint' => 'https://dce.test', 'dcr_id' => 'dcr-1', 'stream_name' => 'Custom-TedcSecurity_CL']);
        SecurityEvents::record('permission_denied', null, 'denied');
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'AAD']), 'dce.test/*' => Http::response('', 204)]);
        $this->assertSame(['sent' => 1, 'failed' => 0], app(SiemForwarder::class)->flush());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'login.microsoftonline.com/tenant-1/oauth2/v2.0/token') && $r['scope'] === 'https://monitor.azure.com/.default');
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://dce.test/dataCollectionRules/dcr-1/streams/Custom-TedcSecurity_CL?api-version=') && $r->header('Authorization')[0] === 'Bearer AAD' && $r[0]['Kind'] === 'security' && isset($r[0]['TimeGenerated']));
    }

    public function test_a_siem_that_is_down_does_not_report_itself_in_a_loop(): void
    {
        $this->siem('splunk_hec', ['url' => 'https://splunk.test:8088', 'token' => 't']);
        Http::fake(['splunk.test:8088/*' => Http::response('down', 503)]);
        SecurityEvents::record('login_failed', null, 'failed');
        app(SiemForwarder::class)->flush();
        $before = SiemOutbox::count();
        app(SiemForwarder::class)->flush();
        $this->assertSame($before, SiemOutbox::count());                       // the failed delivery created no new event about itself
        $this->assertSame(0, SecurityEvent::where('type', 'integration_failure')->count());
    }

    public function test_a_siem_can_be_configured_only_by_those_allowed_to_manage_integrations(): void
    {
        $this->asUser($this->makeUser())->putJson('/api/v1/admin/integrations/siem', ['driver' => 'fake', 'enabled' => true])->assertForbidden();
        $r = $this->asUser($this->admin)->putJson('/api/v1/admin/integrations/siem', ['driver' => 'splunk_hec', 'enabled' => true, 'settings' => ['url' => 'https://s.test', 'token' => 'secret-token']])->assertOk();
        $this->assertArrayNotHasKey('token', $r->json('data.settings'));
        $this->assertTrue($r->json('data.secrets_set.token'));
    }

    // ---- classification and anonymisation ----------------------------------------------------------

    public function test_every_column_is_classified_secrets_are_restricted_and_the_register_is_current(): void
    {
        $c = app(DataClassification::class);
        $map = $c->map();
        $this->assertNotEmpty($map);
        foreach ($map as $table => $cols) {
            foreach ($cols as $col => $class) {
                $this->assertContains($class, ['public', 'internal', 'confidential', 'restricted'], "{$table}.{$col}");
            }
        }
        foreach (['employees.national_id', 'users.password', 'users.remember_token', 'users.mfa_secret', 'integrations.config'] as $key) {
            [$t, $col] = explode('.', $key);
            $this->assertSame('restricted', $map[$t][$col] ?? null, $key);
        }
        $this->assertSame('confidential', $map['users']['email']);
        $this->assertSame('confidential', $map['employees']['employee_no'] ?? 'confidential');
        // The encrypted ones really are encrypted at rest.
        $emp = $this->makeEmployee(['national_id' => '29850123456']);
        $this->assertSame('29850123456', Employee::find($emp->id)->national_id);
        $this->assertStringNotContainsString('29850123456', (string) DB::table('employees')->where('id', $emp->id)->value('national_id'));
        $this->assertSame(File::get(base_path('../docs/security/data-classification.md')), $c->markdown(), 'Run: php artisan tedc:data-classification --write');
    }

    public function test_the_anonymised_export_drops_secrets_pseudonymises_people_keeps_joins_and_is_approved_and_logged(): void
    {
        $emp = $this->makeEmployee(['national_id' => '29850123456'], $this->makeUser(Role::EMPLOYEE, ['email' => 'real.person@moe.gov.qa', 'name' => 'Real Person']));
        $dir = storage_path('app/anonymised/test-'.uniqid());
        $this->artisan('tedc:anonymise-export', ['--reason' => 'short', '--approved-by' => $this->admin->email, '--confirm' => true, '--out' => $dir])->assertFailed();      // reason too short
        $this->artisan('tedc:anonymise-export', ['--reason' => 'Investigating duplicate certificates', '--approved-by' => $emp->user->email, '--confirm' => true, '--out' => $dir])->assertFailed();   // approver lacks the permission
        $this->artisan('tedc:anonymise-export', ['--reason' => 'Investigating duplicate certificates', '--approved-by' => $this->admin->email, '--out' => $dir])->assertSuccessful();   // dry run
        $this->assertFileDoesNotExist($dir.'/MANIFEST.json');
        $this->artisan('tedc:anonymise-export', ['--reason' => 'Investigating duplicate certificates', '--approved-by' => $this->admin->email, '--confirm' => true, '--out' => $dir])->assertSuccessful();

        $all = '';
        foreach (glob($dir.'/*.ndjson') as $f) {
            $all .= file_get_contents($f);
        }
        foreach (['real.person@moe.gov.qa', 'Real Person', '29850123456', 'Secret#12345'] as $leak) {
            $this->assertStringNotContainsString($leak, $all, $leak);
        }
        $users = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($dir.'/users.ndjson'))));
        $row = collect($users)->firstWhere('id', $emp->user->id);
        $this->assertStringEndsWith('@example.invalid', $row['email']);
        $this->assertStringStartsWith('Person ', $row['name']);
        $this->assertNull($row['password']);
        $this->assertSame($emp->user->id, $row['id']);                                              // identifiers stay, so tables still join
        $employees = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($dir.'/employees.ndjson'))));
        $this->assertSame($emp->user->id, collect($employees)->firstWhere('id', $emp->id)['user_id']);
        $this->assertNull(collect($employees)->firstWhere('id', $emp->id)['national_id']);
        $this->assertFileDoesNotExist($dir.'/sessions.ndjson');
        $this->assertFileDoesNotExist($dir.'/integrations.ndjson');
        $manifest = json_decode(file_get_contents($dir.'/MANIFEST.json'), true);
        $this->assertSame($this->admin->email, $manifest['approved_by']);
        $this->assertSame(1, AuditLog::where('action', 'anonymised_export')->count());
        File::deleteDirectory($dir);
    }

    public function test_pseudonyms_are_stable_inside_one_export_and_different_between_exports(): void
    {
        $cols = ['email' => 'confidential', 'id' => 'public'];
        $a = new Anonymiser(app(DataClassification::class));
        $b = new Anonymiser(app(DataClassification::class));
        $m1 = $a->mask('users', ['id' => '1', 'email' => 'x@y.qa'], $cols);
        $m2 = $a->mask('other', ['id' => '9', 'email' => 'X@Y.QA'], $cols);
        $this->assertSame($m1['email'], $m2['email']);
        $this->assertNotSame($m1['email'], $b->mask('users', ['id' => '1', 'email' => 'x@y.qa'], $cols)['email']);
    }

    // ---- data-subject rights -----------------------------------------------------------------------

    public function test_a_person_downloads_their_data_without_secrets_and_it_is_recorded(): void
    {
        $emp = $this->makeEmployee(['national_id' => '29850123456'], $this->makeUser(Role::EMPLOYEE, ['name' => 'Data Subject']));
        AppNotification::create(['user_id' => $emp->user->id, 'type' => 'x', 'title_ar' => 'ع', 'title_en' => 'Hello']);
        $r = $this->asUser($emp->user)->get('/api/v1/me/privacy/export')->assertOk();
        $this->assertStringContainsString('attachment', $r->headers->get('Content-Disposition'));
        $d = json_decode($r->getContent(), true);
        $this->assertSame('Data Subject', $d['account']['name']);
        $this->assertSame('29850123456', $d['employee']['national_id']);                              // their own identifier, to them only
        $this->assertSame('Hello', $d['sections']['notifications'][0]['title_en']);
        $text = $r->getContent();
        $this->assertStringNotContainsString('password', $text);
        $this->assertSame(1, AuditLog::where('action', 'personal_data_exported')->where('auditable_id', $emp->user->id)->count());
        $this->assertSame(1, SecurityEvent::where('type', 'personal_data_export')->count());
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/me/privacy/export')->assertUnauthorized();
    }

    public function test_requests_to_correct_erase_or_object_are_tracked_for_30_days_and_answered(): void
    {
        $handler = $this->makeUser(Role::CENTER_ADMIN);
        $emp = $this->makeUser();
        $r = $this->asUser($emp)->postJson('/api/v1/me/privacy/requests', ['type' => 'erasure', 'details' => 'Please delete my account'])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, strtotime($r['due_at']), 5);
        $this->asUser($emp)->postJson('/api/v1/me/privacy/requests', ['type' => 'erasure'])->assertStatus(422);        // one open request of a kind
        $this->asUser($emp)->postJson('/api/v1/me/privacy/requests', ['type' => 'nonsense'])->assertStatus(422);
        $this->assertSame(2, AppNotification::where('type', 'dsr.received')->count());                                 // both admins hold the permission

        $this->asUser($emp)->getJson('/api/v1/admin/privacy/requests')->assertForbidden();
        $this->asUser($handler)->putJson("/api/v1/admin/privacy/requests/{$r['id']}", ['status' => 'completed'])->assertStatus(422);     // an answer is required
        $this->asUser($handler)->putJson("/api/v1/admin/privacy/requests/{$r['id']}", ['status' => 'rejected', 'resolution' => 'Records must be kept for audit by law; account restricted instead.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(1, AppNotification::where('user_id', $emp->id)->where('type', 'dsr.decided')->count());
        $this->assertSame(1, AuditLog::where('action', 'dsr_rejected')->count());
        $this->assertSame('rejected', $this->asUser($emp)->getJson('/api/v1/me/privacy/requests')->json('data.0.status'));
        $this->asUser($emp)->postJson('/api/v1/me/privacy/requests', ['type' => 'erasure'])->assertCreated();            // a new one is possible once closed
    }

    public function test_handlers_are_reminded_before_and_after_the_due_date_once(): void
    {
        $this->makeUser(Role::CENTER_ADMIN);
        $svc = app(DataSubjectService::class);
        $r = $svc->request($this->makeUser(), 'access', null);
        $this->assertSame(0, $svc->sweep());                                          // 30 days away: nothing yet
        DataSubjectRequest::whereKey($r->id)->update(['due_at' => now()->addDays(3)]);
        $this->assertSame(1, $svc->sweep());
        $this->assertSame(0, $svc->sweep());                                          // once
        DataSubjectRequest::whereKey($r->id)->update(['due_at' => now()->subDay()]);
        $this->assertSame(1, $svc->sweep());                                          // and once more when overdue
        $res = $this->asUser($this->admin)->getJson('/api/v1/admin/privacy/requests');
        $this->assertTrue($res->json('data.0.overdue'));
    }

    // ---- realtime ----------------------------------------------------------------------------------

    public function test_the_realtime_driver_is_a_server_setting_and_sse_streams_only_my_notifications(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $this->asUser($me)->getJson('/api/v1/me/realtime/config')->assertOk()->assertJsonPath('data.driver', 'polling')->assertJsonPath('data.stream', null);
        $this->asUser($me)->get('/api/v1/me/realtime/stream')->assertNotFound();                  // off unless the driver is sse

        config(['tedc.realtime.driver' => 'sse']);
        $this->asUser($me)->getJson('/api/v1/me/realtime/config')->assertJsonPath('data.driver', 'sse')->assertJsonPath('data.stream', '/me/realtime/stream');
        $since = now()->subMinute()->toIso8601String();
        AppNotification::create(['user_id' => $me->id, 'type' => 'a', 'title_ar' => 'ع', 'title_en' => 'For me']);
        AppNotification::create(['user_id' => $other->id, 'type' => 'a', 'title_ar' => 'ع', 'title_en' => 'For someone else']);
        $r = $this->asUser($me)->get('/api/v1/me/realtime/stream?window=1&since='.urlencode($since));
        $r->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $body = $r->streamedContent();
        $this->assertStringContainsString('event: notification', $body);
        $this->assertStringContainsString('For me', $body);
        $this->assertStringNotContainsString('For someone else', $body);
        $this->assertStringContainsString('event: end', $body);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/me/realtime/stream')->assertUnauthorized();
    }
}
