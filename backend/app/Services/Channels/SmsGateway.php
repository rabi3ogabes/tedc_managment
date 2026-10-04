<?php

namespace App\Services\Channels;

use App\Support\Supabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends one text message through the provider chosen in the settings. Never throws: it returns the outcome. */
class SmsGateway
{
    public function __construct(private readonly ChannelSettings $settings) {}

    /** @return array{ok: bool, error: ?string} */
    public function send(string $to, string $message): array
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
                'http' => $this->custom($s, $secrets, $to, $message),
                default => ['ok' => false, 'error' => 'not_configured'],
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 280)];
        }
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
