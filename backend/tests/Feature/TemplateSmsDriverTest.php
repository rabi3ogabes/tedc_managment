<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\ContractValidator;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\SmsGateway;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NTF-03: the SMS gateway is described, not coded. Its request is a template, the encoding follows the text (UCS-2 for Arabic, GSM 7-bit
 * for Latin) under the names the gateway uses, a rule says what counts as accepted, a path finds the message id, and a status table
 * turns the gateway's delivery codes into delivered or failed. Hudhud's assumed shape is the starting preset beside three common ones.
 */
class TemplateSmsDriverTest extends TestCase
{
    private const BASE = 'https://sms.test';

    private function gateway(array $extra = []): void
    {
        app(ChannelSettings::class)->update(['sms' => ['enabled' => true, 'driver' => 'hudhud', 'hudhud_base_url' => self::BASE, 'hudhud_api_key' => 'SMS-KEY-1', 'hudhud_receipt_secret' => 'rcpt-secret', 'sender' => 'TEDC'] + $extra]);
    }

    private function preset(string $key): array
    {
        return json_decode((string) file_get_contents(resource_path("contracts/hudhud/presets/{$key}.json")), true);
    }

    private function usePreset(string $key): void
    {
        $preset = $this->preset($key);
        $store = app(ContractStore::class);
        $store->save('hudhud', 'contract', $preset['contract'], null, 'Preset '.$key);
        $store->save('hudhud', 'mapping', $preset['mapping'], null, 'Preset '.$key);
    }

    public function test_arabic_goes_as_ucs2_hex_and_latin_as_gsm_text_under_the_names_the_gateway_uses(): void
    {
        $this->gateway();
        Http::fake([self::BASE.'/*' => Http::response(['message_id' => 'MSG-1', 'status' => 'queued'])]);

        $this->assertSame(['ok' => true, 'error' => null, 'id' => 'MSG-1'], app(SmsGateway::class)->send('97455512345', 'مرحبا', 'ref-1'));
        $this->assertSame(['ok' => true, 'error' => null, 'id' => 'MSG-1'], app(SmsGateway::class)->send('97455512345', 'Your session starts at 10:30', 'ref-2'));

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/api/v1/messages' && $r->hasHeader('Authorization', 'Bearer SMS-KEY-1') && $r['encoding'] === 'UCS2' && $r['message'] === '06450631062D06280627'
            && $r['message_format'] === 'hex' && $r['recipients'] === ['97455512345'] && $r['reference'] === 'ref-1' && $r['sender'] === 'TEDC');
        Http::assertSent(fn (Request $r) => $r['encoding'] === 'GSM' && $r['message'] === 'Your session starts at 10:30' && ! isset($r['message_format']) && ! isset($r['callback_url']));
    }

    public function test_the_receipt_address_travels_with_the_message_when_it_is_set(): void
    {
        $this->gateway(['hudhud_receipt_url' => 'https://tedc.example/api/v1/integrations/sms/hudhud/receipt']);
        Http::fake([self::BASE.'/*' => Http::response(['message_id' => 'MSG-1'])]);

        app(SmsGateway::class)->send('97455512345', 'Hello');

        Http::assertSent(fn (Request $r) => $r['callback_url'] === 'https://tedc.example/api/v1/integrations/sms/hudhud/receipt');
    }

    public function test_a_gateway_that_answers_200_but_says_it_failed_is_a_failure_with_its_reason(): void
    {
        $this->gateway();
        Http::fake([self::BASE.'/*' => Http::response(['status' => 'failed', 'message' => 'Sender id is not approved'])]);

        $result = app(SmsGateway::class)->send('97455512345', 'Hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Sender id is not approved', (string) $result['error']);
        $this->assertStringNotContainsString('SMS-KEY-1', (string) $result['error']);
    }

    public function test_an_error_status_never_carries_the_key_and_a_refusal_is_a_clear_reason(): void
    {
        $this->gateway();
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Bad token SMS-KEY-1'], 401)]);

        $result = app(SmsGateway::class)->send('97455512345', 'Hello');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('refused the credentials', (string) $result['error']);
        $this->assertStringNotContainsString('SMS-KEY-1', (string) $result['error'], 'a key the gateway echoes back is taken out');
    }

    public function test_the_message_id_can_be_found_in_the_places_gateways_put_it(): void
    {
        $this->gateway();
        foreach ([['message_id' => 'A-1'], ['id' => 'B-2'], ['data' => ['message_id' => 'C-3']]] as $i => $body) {
            Http::swap(new HttpFactory);
            Http::fake([self::BASE.'/*' => Http::response($body + ['status' => 'queued'])]);
            $this->assertSame(['A-1', 'B-2', 'C-3'][$i], app(SmsGateway::class)->send('97455512345', 'Hello')['id']);
        }
    }

    public function test_a_user_name_and_password_are_used_when_there_is_no_token(): void
    {
        $this->gateway(['hudhud_api_key' => '', 'clear' => ['hudhud_api_key' => true], 'hudhud_username' => 'tedc', 'hudhud_password' => 'p@ss']);
        Http::fake([self::BASE.'/*' => Http::response(['message_id' => 'MSG-1'])]);

        $this->assertTrue(app(SmsGateway::class)->send('97455512345', 'Hello')['ok']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('tedc:p@ss')));
    }

    public function test_the_three_common_shapes_are_presets_that_send_what_they_describe(): void
    {
        $this->gateway(['hudhud_api_key' => 'SMS-KEY-1', 'hudhud_username' => 'tedc', 'hudhud_password' => 'p@ss', 'hudhud_send_path' => '/x']);
        $to = '97455512345';

        $this->usePreset('json_post');
        Http::fake([self::BASE.'/*' => Http::response(['id' => 'J-1'])]);
        $this->assertSame('J-1', app(SmsGateway::class)->send($to, 'مرحبا بك', 'r1')['id']);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/x' && $r->method() === 'POST' && $r['to'] === '+'.$to && $r['text'] === 'مرحبا بك' && $r['from'] === 'TEDC' && $r['reference'] === 'r1' && $r->hasHeader('Authorization', 'Bearer SMS-KEY-1'));

        $this->usePreset('form_post');
        Http::swap(new HttpFactory);
        Http::fake([self::BASE.'/*' => Http::response(['sid' => 'F-1', 'status' => 'queued'])]);
        $this->assertSame('F-1', app(SmsGateway::class)->send($to, 'Hello there')['id']);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/x' && str_contains($r->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded') && $r['To'] === '+'.$to && $r['Body'] === 'Hello there' && $r['From'] === 'TEDC'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('tedc:p@ss')));

        $this->usePreset('get_query');
        Http::swap(new HttpFactory);
        Http::fake([self::BASE.'/*' => Http::response('OK|G-7')]);
        $this->assertTrue(app(SmsGateway::class)->send($to, 'مرحبا')['ok']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), self::BASE.'/x?') && str_contains($r->url(), 'to='.$to) && str_contains($r->url(), 'text='.rawurlencode('مرحبا')) && str_contains($r->url(), 'apikey=SMS-KEY-1'));

        Http::swap(new HttpFactory);
        Http::fake([self::BASE.'/*' => Http::response('ERR|no credit')]);
        $this->assertFalse(app(SmsGateway::class)->send($to, 'Hello')['ok'], 'a plain-text answer without OK is a refusal');
    }

    public function test_every_preset_passes_the_same_checks_as_a_contract_an_administrator_saves(): void
    {
        $store = app(ContractStore::class);
        foreach (['hudhud', 'json_post', 'form_post', 'get_query'] as $key) {
            $preset = $this->preset($key);
            $this->assertSame([], ContractValidator::problems('hudhud', 'contract', $preset['contract'], $store->secretSettings('hudhud'), $store->knownSettings('hudhud')), "{$key} contract");
            $this->assertSame([], ContractValidator::problems('hudhud', 'mapping', $preset['mapping'], $store->secretSettings('hudhud'), $store->knownSettings('hudhud')), "{$key} mapping");
            $this->assertNotEmpty($preset['label']['ar'].$preset['label']['en'].$preset['description']['ar'].$preset['description']['en']);
        }
        $this->assertSame($store->bundled('hudhud')->document()['operations'], $this->preset('hudhud')['contract']['operations'], 'the first preset is what ships');
    }

    public function test_delivery_receipts_follow_the_contracts_status_table_and_signature(): void
    {
        $this->gateway();
        $mapping = app(ContractStore::class)->active('hudhud')->mappingDocument();
        $mapping['entities']['receipt']['fields'] = ['message_id' => '$.msgid', 'status' => ['path' => '$.code', 'transform' => ['table:status']], 'reason' => '$.detail'];
        $mapping['entities']['receipt']['tables']['status'] = ['0' => 'delivered', '1' => 'failed', '2' => 'failed', '9' => 'pending'];
        app(ContractStore::class)->save('hudhud', 'mapping', $mapping, null);
        $contract = app(ContractStore::class)->active('hudhud')->document();
        $contract['inbound'] = ['signature' => ['header' => 'X-Gw-Sign', 'algorithm' => 'hmac-sha512', 'encoding' => 'base64', 'payload' => '{{ body }}', 'secret_setting' => 'receipt_secret'], 'receipt' => ['records' => '$.items']];
        app(ContractStore::class)->save('hudhud', 'contract', $contract, null);

        $user = $this->makeUser(Role::EMPLOYEE);
        $one = NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'x', 'status' => 'sent', 'provider_message_id' => 'G-1']);
        $two = NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'x', 'status' => 'sent', 'provider_message_id' => 'G-2']);
        $three = NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'x', 'status' => 'sent', 'provider_message_id' => 'G-3']);
        $body = json_encode(['items' => [['msgid' => 'G-1', 'code' => 0], ['msgid' => 'G-2', 'code' => 2, 'detail' => 'handset off'], ['msgid' => 'G-3', 'code' => 9]]]);
        $send = fn (string $signature) => $this->call('POST', '/api/v1/integrations/sms/hudhud/receipt', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GW_SIGN' => $signature], $body);

        $send('bad')->assertUnauthorized();
        $send(base64_encode(hash_hmac('sha512', $body, 'rcpt-secret', true)))->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame('delivered', $one->fresh()->status);
        $this->assertSame(['failed', 'handset off'], [$two->fresh()->status, $two->fresh()->failed_reason]);
        $this->assertSame('sent', $three->fresh()->status, 'a code the table calls pending changes nothing');
        $send(base64_encode(hash_hmac('sha512', $body, 'rcpt-secret', true)))->assertOk();   // a repeat is harmless
        $this->assertSame('delivered', $one->fresh()->status);
    }

    public function test_a_repeated_receipt_is_harmless(): void
    {
        $this->gateway();
        $user = $this->makeUser(Role::EMPLOYEE);
        $then = now()->subHour();
        $row = NotificationDelivery::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'x', 'status' => 'delivered', 'provider_message_id' => 'MSG-9', 'delivered_at' => $then]);
        $body = json_encode(['message_id' => 'MSG-9', 'status' => 'delivered']);

        $this->call('POST', '/api/v1/integrations/sms/hudhud/receipt', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUDHUD_SIGNATURE' => hash_hmac('sha256', $body, 'rcpt-secret')], $body)->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertSame($then->toDateTimeString(), $row->fresh()->delivered_at->toDateTimeString(), 'the time it was delivered is not moved by a repeat');
    }
}
