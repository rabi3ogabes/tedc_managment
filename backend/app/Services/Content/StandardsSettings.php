<?php

namespace App\Services\Content;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Settings → Standards: the LRS credentials, forwarding to an external LRS, the Caliper endpoint and offline learning. */
class StandardsSettings
{
    public const KEY = 'standards';

    public static function defaults(): array
    {
        return [
            'lrs_forward' => ['enabled' => false, 'endpoint' => '', 'key' => '', 'secret' => ''],
            'caliper' => ['enabled' => false, 'endpoint' => '', 'api_key' => '', 'sensor_id' => ''],
            'lrs_credentials' => [],
            'offline' => ['enabled' => false, 'expiry_days' => 14],
        ];
    }

    public function all(): array
    {
        return Cache::remember('site.standards', 30, fn () => array_replace_recursive(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    /** What an administrator sees: secrets are masked. */
    public function masked(): array
    {
        $a = $this->all();
        foreach (['lrs_forward' => 'secret', 'caliper' => 'api_key'] as $k => $f) {
            $a[$k][$f] = $a[$k][$f] !== '' ? '••••••••' : '';
        }
        $a['lrs_credentials'] = array_map(fn ($c) => Arr::only($c, ['key', 'label', 'scope']), $a['lrs_credentials']);

        return $a;
    }

    public function update(array $in, ?User $by = null): array
    {
        $cur = $this->all();
        foreach (['lrs_forward' => ['enabled', 'endpoint', 'key', 'secret'], 'caliper' => ['enabled', 'endpoint', 'api_key', 'sensor_id']] as $k => $fields) {
            foreach ($fields as $f) {
                if (isset($in[$k]) && array_key_exists($f, $in[$k]) && $in[$k][$f] !== '••••••••') {
                    $cur[$k][$f] = $f === 'enabled' ? (bool) $in[$k][$f] : (string) $in[$k][$f];
                }
            }
        }
        if (isset($in['offline'])) {
            $cur['offline'] = ['enabled' => (bool) ($in['offline']['enabled'] ?? false), 'expiry_days' => max(1, min(365, (int) ($in['offline']['expiry_days'] ?? 14)))];
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $cur, 'updated_by' => $by?->id]);
        Cache::forget('site.standards');

        return $this->masked();
    }

    /** Creates LRS credentials; the secret is shown once. @return array{key: string, secret: string} */
    public function addCredential(string $label, string $scope, ?User $by = null): array
    {
        $cur = $this->all();
        $key = 'lrs_'.Str::lower(Str::random(10));
        $secret = Str::random(40);
        $cur['lrs_credentials'][] = ['key' => $key, 'secret_hash' => hash('sha256', $secret), 'label' => $label, 'scope' => $scope];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $cur, 'updated_by' => $by?->id]);
        Cache::forget('site.standards');

        return ['key' => $key, 'secret' => $secret];
    }

    public function removeCredential(string $key, ?User $by = null): void
    {
        $cur = $this->all();
        $cur['lrs_credentials'] = array_values(array_filter($cur['lrs_credentials'], fn ($c) => $c['key'] !== $key));
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $cur, 'updated_by' => $by?->id]);
        Cache::forget('site.standards');
    }

    /** @return array{scope: string}|null */
    public function credential(string $key, string $secret): ?array
    {
        foreach ($this->all()['lrs_credentials'] as $c) {
            if ($c['key'] === $key && hash_equals($c['secret_hash'], hash('sha256', $secret))) {
                return ['scope' => $c['scope']];
            }
        }

        return null;
    }
}
