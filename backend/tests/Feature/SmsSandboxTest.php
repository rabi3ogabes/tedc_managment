<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractStore;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\Channels\ChannelSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The SMS sandbox: a test message to the administrator's own phone that shows the exact request (credentials masked) and what the gateway answered. */
class SmsSandboxTest extends TestCase
{
    private const BASE = 'https://sms.test';

    private function gateway(string $driver = 'hudhud'): void
    {
        app(ChannelSettings::class)->update(['sms' => ['enabled' => true, 'driver' => $driver, 'hudhud_base_url' => self::BASE, 'hudhud_api_key' => 'SMS-KEY-1', 'hudhud_receipt_secret' => 'rcpt-secret', 'sender' => 'TEDC']]);
    }

    private function admin(?string $phone = '55512345')
    {
        return $this->makeUser(Role::CENTER_ADMIN, ['phone' => $phone]);
    }

    public function test_it_shows_the_exact_request_with_the_credentials_masked_and_sends_to_the_admins_own_number(): void
    {
        $this->gateway();
        Http::fake([self::BASE.'/*' => Http::response(['message_id' => 'MSG-1', 'status' => 'queued'])]);
        $admin = $this->admin();

        $r = $this->asUser($admin)->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'مرحبا'])->assertOk();

        $this->assertTrue($r->json('data.sent'));
        $this->assertTrue($r->json('data.ok'));
        $this->assertSame(['POST', self::BASE.'/api/v1/messages'], [$r->json('data.request.method'), $r->json('data.request.url')]);
        $this->assertSame('•••', $r->json('data.request.headers.Authorization'));
        $body = json_decode($r->json('data.request.body'), true);
        $this->assertSame(['UCS2', '06450631062D06280627', ['97455512345']], [$body['encoding'], $body['message'], $body['recipients']], 'the number is the administrator\'s own');
        $this->assertSame(['ucs2', 'UCS2', 5, 1], [$r->json('data.encoding.alphabet'), $r->json('data.encoding.name'), $r->json('data.encoding.length'), $r->json('data.encoding.segments')]);
        $this->assertSame(['status' => 200, 'message_id' => 'MSG-1', 'gateway_status' => 'queued'], collect($r->json('data.response'))->only(['status', 'message_id', 'gateway_status'])->all());
        $this->assertSame('+974•••345', $r->json('data.to'));
        $this->assertStringNotContainsString('SMS-KEY-1', $r->getContent());
        Http::assertSent(fn (Request $q) => $q->hasHeader('Authorization', 'Bearer SMS-KEY-1') && $q['recipients'] === ['97455512345']);
        $audit = AuditLog::where('action', 'sms.sandbox_sent')->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame([true, 'ucs2'], [$audit->new_values['ok'], $audit->new_values['alphabet']]);
        $this->assertStringNotContainsString('مرحبا', json_encode($audit->new_values, JSON_UNESCAPED_UNICODE), 'the audit holds no message text');
    }

    public function test_a_dry_run_shows_the_request_and_sends_nothing(): void
    {
        $this->gateway();
        Http::fake();

        $r = $this->asUser($this->admin())->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'Hello', 'dry' => true])->assertOk();

        $this->assertFalse($r->json('data.sent'));
        $this->assertNull($r->json('data.response'));
        $this->assertSame('GSM', json_decode($r->json('data.request.body'), true)['encoding']);
        $this->assertSame('gsm7', $r->json('data.encoding.alphabet'));
        Http::assertNothingSent();
    }

    public function test_a_long_arabic_text_reports_how_many_parts_it_takes(): void
    {
        $this->gateway();
        Http::fake();

        $r = $this->asUser($this->admin())->postJson('/api/v1/admin/sms/sandbox-send', ['text' => str_repeat('م', 71), 'dry' => true])->assertOk();

        $this->assertSame([71, 2], [$r->json('data.encoding.length'), $r->json('data.encoding.segments')]);
    }

    public function test_the_gateways_refusal_is_part_of_the_answer_and_never_holds_the_key(): void
    {
        $this->gateway();
        Http::fake([self::BASE.'/*' => Http::response(['status' => 'failed', 'message' => 'Sender id is not approved: SMS-KEY-1'])]);

        $r = $this->asUser($this->admin())->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'Hello'])->assertOk();

        $this->assertFalse($r->json('data.ok'));
        $this->assertStringContainsString('Sender id is not approved', $r->json('data.error'));
        $this->assertStringNotContainsString('SMS-KEY-1', $r->getContent());
        $this->assertSame(200, $r->json('data.response.status'));
    }

    public function test_it_needs_the_permission_the_admins_own_number_and_the_template_driver(): void
    {
        $this->gateway();
        Http::fake();
        $this->asUser($this->makeUser(Role::EMPLOYEE, ['phone' => '55512345']))->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'x'])->assertForbidden();
        $this->asUser($this->admin(null))->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'x'])->assertStatus(422);
        $this->asUser($this->admin())->postJson('/api/v1/admin/sms/sandbox-send', ['text' => str_repeat('x', 501)])->assertStatus(422);

        $this->gateway('log');
        $this->asUser($this->admin())->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'x'])->assertStatus(422);
        Http::assertNothingSent();

        app(ChannelSettings::class)->update(['sms' => ['driver' => 'hudhud', 'hudhud_base_url' => '']]);
        $this->asUser($this->admin())->postJson('/api/v1/admin/sms/sandbox-send', ['text' => 'x'])->assertStatus(422);   // no address: nothing to show
    }

    public function test_the_presets_are_offered_with_their_contract_and_mapping(): void
    {
        $r = $this->asUser($this->admin())->getJson('/api/v1/admin/sms/presets')->assertOk();

        $this->assertSame(['hudhud', 'json_post', 'form_post', 'get_query'], array_column($r->json('data'), 'key'));
        $this->assertSame('POST', $r->json('data.1.contract.operations.send.method'));
        $this->assertSame('GET', $r->json('data.3.contract.operations.send.method'));
        $this->assertNotEmpty($r->json('data.0.mapping.entities.receipt.tables.status'));
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/sms/presets')->assertForbidden();
    }

    public function test_the_contract_tabs_work_for_the_gateway_too(): void
    {
        $admin = $this->admin();
        $r = $this->asUser($admin)->getJson('/api/v1/admin/integrations/hudhud/contract')->assertOk();
        $this->assertSame('send', $r->json('data.operations.0.name'));
        $this->assertContains('api_key', $r->json('data.secret_settings'));
        $run = $this->asUser($admin)->postJson('/api/v1/admin/integrations/hudhud/test-case', ['mode' => 'contract'])->assertOk();
        $this->assertTrue($run->json('data.ok'), $run->getContent());
        $this->assertSame(5, $run->json('data.total'));
        $this->assertSame(ContractStore::ALIASES['mawared'], 'hr');
    }

    public function test_a_receipt_mapping_can_be_tried_on_a_recorded_receipt_before_it_is_saved(): void
    {
        $r = $this->asUser($this->admin())->postJson('/api/v1/admin/integrations/hudhud/contract/preview', ['entity' => 'receipt', 'fixture' => 'receipts.ok'])->assertOk();

        $this->assertSame(3, $r->json('total'));
        $this->assertSame(['message_id' => 'MSG-1', 'status' => 'delivered'], $r->json('records.0.mapped'));
        $this->assertSame(['message_id' => 'MSG-2', 'status' => 'failed', 'reason' => 'handset off'], $r->json('records.1.mapped'));
        $this->assertSame('queued', $r->json('records.2.mapped.status'), 'a code the table does not know passes through, and settles nothing');
    }
}
