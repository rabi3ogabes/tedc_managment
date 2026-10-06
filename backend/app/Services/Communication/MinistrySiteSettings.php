<?php

namespace App\Services\Communication;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Settings → Ministry website: where news and events are pushed, and whether items flagged for export go out on their own. */
class MinistrySiteSettings
{
    public const KEY = 'ministry_site';

    public static function defaults(): array
    {
        return ['enabled' => false, 'endpoint' => '', 'auth_header' => 'X-Api-Key', 'api_key' => null, 'auto_export' => true, 'site_name' => ''];
    }

    public function all(): array
    {
        return Cache::remember('site.ministry_site', 30, fn () => array_replace(self::defaults(), SiteSetting::find(self::KEY)?->value ?? []));
    }

    public function apiKey(): ?string
    {
        $enc = $this->all()['api_key'] ?? null;
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (Throwable) {
            return null;   // APP_KEY changed: enter the key again
        }
    }

    public function masked(): array
    {
        $a = $this->all();
        $a['api_key_set'] = filled($a['api_key']);
        unset($a['api_key']);

        return $a;
    }

    public function ready(): bool
    {
        $a = $this->all();

        return $a['enabled'] && filled($a['endpoint']);
    }

    public function update(array $in, ?User $by = null): array
    {
        $cur = $this->all();
        foreach (['enabled', 'auto_export'] as $k) {
            if (array_key_exists($k, $in)) {
                $cur[$k] = (bool) $in[$k];
            }
        }
        foreach (['endpoint', 'auth_header', 'site_name'] as $k) {
            if (array_key_exists($k, $in)) {
                $cur[$k] = (string) ($in[$k] ?? '');
            }
        }
        if (filled($in['api_key'] ?? null)) {
            $cur['api_key'] = Crypt::encryptString((string) $in['api_key']);
        } elseif (! empty($in['clear_api_key'])) {
            $cur['api_key'] = null;
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $cur, 'updated_by' => $by?->id]);
        Cache::forget('site.ministry_site');

        return $this->masked();
    }
}
