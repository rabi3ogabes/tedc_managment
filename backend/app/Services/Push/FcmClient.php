<?php

namespace App\Services\Push;

use App\Support\Supabase;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Firebase Cloud Messaging HTTP v1 client: signs a service-account JWT (RS256), exchanges it for
 * an OAuth access token (cached ~50 min) and sends messages concurrently.
 */
class FcmClient
{
    public const TOKEN_CACHE = 'push.fcm.access_token';

    public const CHANNEL_ID = 'tedc_general';

    public function __construct(private readonly PushSettings $settings) {}

    public function accessToken(bool $fresh = false): string
    {
        if ($fresh) {
            Cache::forget(self::TOKEN_CACHE);
        }

        return Cache::remember(self::TOKEN_CACHE, now()->addMinutes(50), function () {
            $account = $this->settings->serviceAccount() ?? throw new RuntimeException(__('push.not_configured'));
            $now = time();
            $assertion = JWT::encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $account['private_key'], 'RS256', $account['private_key_id'] ?? null);

            $response = $this->http()->asForm()->post($account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if ($response->failed() || ! $response->json('access_token')) {
                throw new RuntimeException(__('push.auth_failed', ['reason' => $response->json('error_description') ?? $response->json('error') ?? $response->status()]));
            }

            return $response->json('access_token');
        });
    }

    /**
     * @param  list<array{token: string, title: string, body: ?string}>  $messages
     * @param  array<string, string>  $data
     * @return array{delivered: int, failed: int, invalid_tokens: list<string>, error: ?string}
     */
    public function send(array $messages, array $data = []): array
    {
        $account = $this->settings->serviceAccount() ?? throw new RuntimeException(__('push.not_configured'));
        $url = "https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send";
        $token = $this->accessToken();
        $android = $this->settings->all()['android'];
        $data = array_map('strval', array_filter($data, fn ($v) => is_scalar($v)));

        $result = ['delivered' => 0, 'failed' => 0, 'invalid_tokens' => [], 'error' => null];

        foreach (array_chunk($messages, 50) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(fn (array $m) => $pool->as($m['token'])
                ->withOptions(['verify' => Supabase::caBundle()])
                ->timeout(15)
                ->withToken($token)
                ->post($url, ['message' => [
                    'token' => $m['token'],
                    'notification' => array_filter(['title' => $m['title'], 'body' => $m['body']]),
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                        'notification' => ['channel_id' => self::CHANNEL_ID, 'color' => $android['color'], 'icon' => 'ic_stat_notify', 'sound' => 'default'],
                    ],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]]), $chunk));

            foreach ($responses as $deviceToken => $response) {
                if ($response instanceof Response && $response->successful()) {
                    $result['delivered']++;

                    continue;
                }
                $result['failed']++;
                if ($response instanceof Response) {
                    if (self::isInvalidToken($response)) {
                        $result['invalid_tokens'][] = (string) $deviceToken;
                    } else {
                        $result['error'] ??= mb_substr((string) ($response->json('error.message') ?? $response->status()), 0, 300);
                    }
                } else {
                    $result['error'] ??= mb_substr($response instanceof \Throwable ? $response->getMessage() : 'Connection failed', 0, 300);
                }
            }
        }

        return $result;
    }

    private static function isInvalidToken(Response $response): bool
    {
        $details = json_encode($response->json('error.details') ?? []);

        return $response->status() === 404
            || str_contains($details, 'UNREGISTERED')
            || ($response->status() === 400 && str_contains((string) $response->json('error.message'), 'registration token'));
    }

    private function http()
    {
        return Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15);
    }
}
