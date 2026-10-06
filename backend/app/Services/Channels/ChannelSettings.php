<?php

namespace App\Services\Channels;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Settings of the e-mail and SMS channels (push has its own page). Each channel has a master switch and a provider;
 * a channel whose provider is not set up yet is simply reported as "not configured" and its messages are logged as
 * skipped — so everything is prepared and the provider details can be added later without any code change.
 * Passwords and tokens are stored encrypted and never returned to the browser.
 */
class ChannelSettings
{
    public const KEY = 'notification_channels';

    private const CACHE = 'site.notification_channels';

    /** Secret fields (stored encrypted), by channel. */
    private const SECRETS = ['email' => ['smtp_password'], 'sms' => ['twilio_token', 'unifonic_app_sid', 'http_auth', 'hudhud_api_key', 'hudhud_password', 'hudhud_receipt_secret']];

    public static function defaults(): array
    {
        return [
            // Which channels are on by default for every notification (push, e-mail, SMS) — the administrator can narrow it per event and per task.
            'defaults' => ['push' => true, 'email' => true, 'sms' => true],
            'email' => [
                'enabled' => true, 'driver' => 'none',    // none | log | smtp
                'from_name' => null, 'from_address' => null, 'reply_to' => null,
                'smtp_host' => null, 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => null,
            ],
            'sms' => [
                'enabled' => true, 'driver' => 'none',    // none | log | hudhud | twilio | unifonic | http
                'sender' => null, 'default_country_code' => '974',
                'hudhud_base_url' => null, 'hudhud_send_path' => '/api/v1/messages', 'hudhud_username' => null, 'hudhud_receipt_url' => null,
                'twilio_sid' => null, 'twilio_from' => null,
                'http_url' => null, 'http_method' => 'POST', 'http_format' => 'json', 'http_headers' => null, 'http_body' => '{"to":"{{to}}","message":"{{message}}","sender":"{{sender}}"}',
            ],
            'secrets' => ['email' => null, 'sms' => null],    // encrypted JSON
        ];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, function () {
            $stored = SiteSetting::find(self::KEY)?->value ?? [];

            return array_replace_recursive(self::defaults(), Arr::only($stored, array_keys(self::defaults())));
        });
    }

    /** Settings safe for the browser: secrets replaced by "is it set". */
    public function forAdmin(): array
    {
        $all = $this->all();
        foreach (self::SECRETS as $channel => $keys) {
            $set = $this->secrets($channel);
            $all[$channel]['secrets_set'] = array_map(fn ($k) => filled($set[$k] ?? null), array_combine($keys, $keys));
        }
        unset($all['secrets']);
        $all['ready'] = ['email' => $this->ready('email'), 'sms' => $this->ready('sms')];

        return $all;
    }

    /** @return array<string, string|null> decrypted secrets of a channel */
    public function secrets(string $channel): array
    {
        $encrypted = $this->all()['secrets'][$channel] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($encrypted), true) ?: [];
        } catch (Throwable) {
            return []; // APP_KEY changed: the secrets must be entered again.
        }
    }

    public function enabled(string $channel): bool
    {
        return (bool) ($this->all()[$channel]['enabled'] ?? false);
    }

    /** The provider details are complete, so messages can really leave. */
    public function ready(string $channel): bool
    {
        $s = $this->all()[$channel] ?? [];
        $secrets = $this->secrets($channel);

        return match ($channel) {
            'email' => match ($s['driver'] ?? 'none') {
                'log' => true,
                'smtp' => filled($s['smtp_host']) && filled($s['from_address']),
                default => false,
            },
            'sms' => match ($s['driver'] ?? 'none') {
                'log' => true,
                'hudhud' => filled($s['hudhud_base_url']) && (filled($secrets['hudhud_api_key'] ?? null) || filled($s['hudhud_username'])),
                'twilio' => filled($s['twilio_sid']) && filled($secrets['twilio_token'] ?? null) && filled($s['twilio_from']),
                'unifonic' => filled($secrets['unifonic_app_sid'] ?? null),
                'http' => filled($s['http_url']),
                default => false,
            },
            default => false,
        };
    }

    public function defaultOn(string $channel): bool
    {
        return (bool) ($this->all()['defaults'][$channel] ?? true);
    }

    /** @param  array<string, mixed>  $input */
    public function update(array $input, ?User $by = null): array
    {
        $stored = SiteSetting::find(self::KEY)?->value ?? [];
        $next = array_replace_recursive(self::defaults(), Arr::only($stored, array_keys(self::defaults())));

        if (isset($input['defaults'])) {
            $next['defaults'] = array_replace($next['defaults'], array_map('boolval', Arr::only($input['defaults'], ['push', 'email', 'sms'])));
        }
        foreach (['email', 'sms'] as $channel) {
            if (! isset($input[$channel])) {
                continue;
            }
            $in = $input[$channel];
            $next[$channel] = array_replace($next[$channel], Arr::only($in, array_keys(self::defaults()[$channel])));
            $next[$channel]['enabled'] = (bool) ($in['enabled'] ?? $next[$channel]['enabled']);

            // A blank secret keeps the stored one; "clear" removes it.
            $secrets = $this->secrets($channel);
            foreach (self::SECRETS[$channel] as $key) {
                if (! empty($in['clear'][$key])) {
                    unset($secrets[$key]);
                } elseif (filled($in[$key] ?? null)) {
                    $secrets[$key] = (string) $in[$key];
                }
            }
            $next['secrets'][$channel] = $secrets ? Crypt::encryptString(json_encode($secrets)) : null;
        }

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->forAdmin();
    }
}
