<?php

namespace App\Services\Channels;

use App\Integrations\Contracts\ContractDriver;
use App\Integrations\Contracts\ContractException;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\SmsEncoding;
use App\Support\Supabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends one text message through the provider chosen in the settings. Never throws: it returns the outcome. */
class SmsGateway
{
    public function __construct(private readonly ChannelSettings $settings) {}

    /** @return array{ok: bool, error: ?string, id?: ?string} */
    public function send(string $to, string $message, ?string $reference = null): array
    {
        $s = $this->settings->all()['sms'];
        $secrets = $this->settings->secrets('sms');
        $http = fn () => Http::withOptions(['verify' => Supabase::caBundle()])->timeout(20);

        try {
            return match ($s['driver']) {
                'log' => $this->log($to, $message),
                'twilio' => $this->result($http()->asForm()->withBasicAuth((string) $s['twilio_sid'], (string) ($secrets['twilio_token'] ?? ''))
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$s['twilio_sid']}/Messages.json", ['To' => '+'.$to, 'From' => $s['twilio_from'], 'Body' => $message]), fn ($r) => $r->status() === 201),
                'unifonic' => $this->result($http()->asForm()->post('https://el.cloud.unifonic.com/rest/SMS/messages', [
                    'AppSid' => $secrets['unifonic_app_sid'] ?? '', 'SenderID' => $s['sender'] ?: 'TEDC', 'Recipient' => $to, 'Body' => $message, 'responseType' => 'JSON',
                ]), fn ($r) => $r->successful() && $r->json('success') !== false),
                'hudhud' => $this->hudhud($s, $secrets, $to, $message, $reference),
                'http' => $this->custom($s, $secrets, $to, $message),
                default => ['ok' => false, 'error' => 'not_configured'],
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 280)];
        }
    }

    /**
     * Hudhud, the Ministry's messaging gateway, or any gateway: the request is the template of the gateway's contract (Settings →
     * Notification channels picks a preset and edits it), Arabic goes in the alphabet and the form the contract names (UCS-2, in hex by
     * default) and Latin text as plain text, a rule in the contract says what counts as accepted, and the provider's message id is kept so
     * its delivery receipt (POST /integrations/sms/hudhud/receipt) can update the same row.
     */
    private function hudhud(array $s, array $secrets, string $to, string $message, ?string $reference): array
    {
        if (! filled($s['hudhud_base_url'] ?? null)) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $answer = $this->driver()->call('send', ['to' => $to, 'text' => $message, 'reference' => $reference]);   // a refusal or a failure is an exception, caught by send()
        $id = $answer->value('message_id');

        return ['ok' => true, 'error' => null, 'id' => is_scalar($id) ? (string) $id : null];
    }

    /**
     * What a test message would send, and (unless $dry) sends it: the exact request with every credential masked, the alphabet and the
     * parts it takes, and what the gateway answered. Never throws: a refusal is part of the answer.
     *
     * @return array{request: array<string, mixed>, encoding: array<string, mixed>, sent: bool, ok: ?bool, error: ?string, response: ?array<string, mixed>}
     */
    public function sandbox(string $to, string $message, bool $dry): array
    {
        $driver = $this->driver();
        $params = ['to' => $to, 'text' => $message, 'reference' => 'sandbox'];
        $info = SmsEncoding::describe($message);
        $prepared = $driver->contract()->prepare($params);
        $out = [
            'request' => $driver->request('send', $params)->masked(),
            'encoding' => ['alphabet' => $info['encoding'], 'name' => $prepared['encoding'] ?? $info['encoding'], 'length' => $prepared['length'] ?? $info['length'], 'segments' => $prepared['segments'] ?? $info['segments']],
            'sent' => false, 'ok' => null, 'error' => null, 'response' => null,
        ];
        if ($dry) {
            return $out;
        }
        $out['sent'] = true;
        try {
            $answer = $driver->call('send', $params);
            $id = $answer->value('message_id');
            $status = $answer->value('status');
            $out['ok'] = true;
            $out['response'] = ['status' => $answer->status, 'body' => mb_substr($driver->scrub($answer->body), 0, 500), 'message_id' => is_scalar($id) ? (string) $id : null, 'gateway_status' => is_scalar($status) ? (string) $status : null];
        } catch (ContractException $e) {
            $out['ok'] = false;
            $out['error'] = $e->getMessage();
            $out['response'] = $e->status > 0 ? ['status' => $e->status, 'body' => null, 'message_id' => null, 'gateway_status' => null] : null;
        }

        return $out;
    }

    /** The driver of the gateway's contract (the shipped one, or the administrator's version of it) with the settings of the SMS channel. */
    private function driver(): ContractDriver
    {
        $store = app(ContractStore::class);

        return new ContractDriver($store->active('hudhud'), $this->settings->contractSettings(), $store->secretSettings('hudhud'), 'hudhud');
    }

    /** A provider that is not one of the built-in ones: the administrator describes the request (URL, headers, body with {{to}}, {{message}}, {{sender}}). */
    private function custom(array $s, array $secrets, string $to, string $message): array
    {
        $fill = fn (string $text, bool $json) => preg_replace_callback('/\{\{\s*(to|message|sender)\s*\}\}/', function ($m) use ($s, $to, $message, $json) {
            $v = ['to' => $to, 'message' => $message, 'sender' => (string) ($s['sender'] ?? '')][$m[1]];

            return $json ? substr((string) json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1) : $v;   // JSON-escaped, without the outer quotes
        }, $text);

        $headers = [];
        foreach (preg_split('/\R/', (string) $s['http_headers']) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = array_map('trim', explode(':', $line, 2));
                $headers[$k] = $v;
            }
        }
        if (filled($secrets['http_auth'] ?? null)) {
            $headers['Authorization'] = $secrets['http_auth'];
        }
        $request = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(20)->withHeaders($headers);
        $json = ($s['http_format'] ?? 'json') === 'json';
        $url = $fill((string) $s['http_url'], false);
        $body = $fill((string) $s['http_body'], $json);

        $response = strtoupper((string) $s['http_method']) === 'GET'
            ? $request->get($url, ['to' => $to, 'message' => $message, 'sender' => $s['sender']])
            : ($json ? $request->withBody($body, 'application/json')->post($url) : $request->asForm()->post($url, $this->parseForm($body)));

        return $this->result($response, fn ($r) => $r->successful());
    }

    private function parseForm(string $body): array
    {
        parse_str($body, $out);

        return $out;
    }

    private function result($response, callable $ok): array
    {
        return $ok($response) ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'HTTP '.$response->status().' '.mb_substr(strip_tags((string) $response->body()), 0, 200)];
    }

    private function log(string $to, string $message): array
    {
        Log::info('SMS (log driver) to +'.$to.': '.$message);

        return ['ok' => true, 'error' => null];
    }

    /** Digits only, with the country code added when the number is local (8 digits or fewer, e.g. a Qatar number). */
    public function normalise(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        $cc = preg_replace('/\D+/', '', (string) ($this->settings->all()['sms']['default_country_code'] ?? '974')) ?? '';

        if (! str_starts_with($raw, '+')) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (strlen($digits) <= 8 && $cc !== '') {
                $digits = $cc.ltrim($digits, '0');
            }
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
}
