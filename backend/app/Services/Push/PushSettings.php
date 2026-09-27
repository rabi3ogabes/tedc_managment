<?php

namespace App\Services\Push;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Firebase push-notification settings, managed by administrators from the web dashboard:
 *  - the service account (server credentials, stored encrypted and never returned to clients);
 *  - the Android app's Firebase client options (public; the mobile app initialises Firebase with them at
 *    runtime, so switching Firebase project needs no new APK);
 *  - which notification categories are pushed.
 */
class PushSettings
{
    public const KEY = 'push';

    private const CACHE = 'site.push';

    /** Notification categories (the prefix of the notification type) that can be pushed. */
    public const CATEGORIES = ['registration', 'session', 'task', 'certificate', 'impact', 'announcement', 'training_need'];

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'service_account' => null, // encrypted JSON
            'client' => ['api_key' => null, 'app_id' => null, 'messaging_sender_id' => null, 'project_id' => null, 'storage_bucket' => null],
            'categories' => array_fill_keys(self::CATEGORIES, true),
            'android' => ['channel_name_ar' => 'إشعارات مركز التدريب', 'channel_name_en' => 'Training center notifications', 'color' => '#8A1538'],
        ];
    }

    public function all(): array
    {
        return Cache::rememberForever(self::CACHE, function () {
            $stored = SiteSetting::find(self::KEY)?->value ?? [];

            return array_replace_recursive(self::defaults(), Arr::only($stored, array_keys(self::defaults())));
        });
    }

    /** Settings safe to show to administrators: the private key is replaced by a summary. */
    public function forAdmin(): array
    {
        $settings = $this->all();
        $account = $this->serviceAccount();
        $settings['service_account'] = $account ? [
            'project_id' => $account['project_id'] ?? null,
            'client_email' => $account['client_email'] ?? null,
            'private_key_id' => isset($account['private_key_id']) ? substr($account['private_key_id'], 0, 8).'…' : null,
        ] : null;
        $settings['ready'] = $this->isReady();

        return $settings;
    }

    /** Public configuration for the mobile app. */
    public function forMobile(): array
    {
        $settings = $this->all();
        $client = $settings['client'];

        return [
            'enabled' => $this->isReady(),
            'options' => $this->isReady() ? $client : null,
            'android' => $settings['android'],
        ];
    }

    public function isReady(): bool
    {
        $settings = $this->all();

        return $settings['enabled'] && $this->serviceAccount() !== null
            && filled($settings['client']['api_key']) && filled($settings['client']['app_id']) && filled($settings['client']['messaging_sender_id']);
    }

    public function allows(string $type): bool
    {
        $category = explode('.', $type)[0];

        return (bool) ($this->all()['categories'][$category] ?? false);
    }

    /** @return array<string, string>|null decoded service-account JSON */
    public function serviceAccount(): ?array
    {
        $encrypted = $this->all()['service_account'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString($encrypted), true) ?: null;
        } catch (Throwable) {
            return null; // APP_KEY changed: the administrator must upload the key again.
        }
    }

    /**
     * @param  array{enabled?: bool, service_account_json?: string|null, remove_service_account?: bool, client?: array, categories?: array, android?: array}  $input
     */
    public function update(array $input, ?User $by = null): array
    {
        $stored = SiteSetting::find(self::KEY)?->value ?? [];
        $next = array_replace_recursive(self::defaults(), Arr::only($stored, array_keys(self::defaults())));

        if (! empty($input['remove_service_account'])) {
            $next['service_account'] = null;
        }
        if (filled($input['service_account_json'] ?? null)) {
            $account = self::parseServiceAccount($input['service_account_json']);
            $next['service_account'] = Crypt::encryptString(json_encode($account));
            // Keep the client options in the same Firebase project.
            $next['client']['project_id'] ??= $account['project_id'];
        }
        foreach (['enabled'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $next[$flag] = (bool) $input[$flag];
            }
        }
        if (isset($input['client'])) {
            $next['client'] = array_replace($next['client'], Arr::only($input['client'], array_keys(self::defaults()['client'])));
        }
        if (isset($input['categories'])) {
            $next['categories'] = array_replace($next['categories'], array_map('boolval', Arr::only($input['categories'], self::CATEGORIES)));
        }
        if (isset($input['android'])) {
            $next['android'] = array_replace($next['android'], Arr::only($input['android'], array_keys(self::defaults()['android'])));
        }

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);
        Cache::forget(FcmClient::TOKEN_CACHE);

        return $this->forAdmin();
    }

    /** @return array<string, string> */
    public static function parseServiceAccount(string $json): array
    {
        $account = json_decode($json, true);

        if (! is_array($account) || ($account['type'] ?? null) !== 'service_account'
            || empty($account['project_id']) || empty($account['client_email']) || empty($account['private_key'])
            || ! str_contains($account['private_key'], 'PRIVATE KEY')) {
            throw ValidationException::withMessages(['service_account_json' => __('push.invalid_service_account')]);
        }

        return Arr::only($account, ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri']);
    }
}
