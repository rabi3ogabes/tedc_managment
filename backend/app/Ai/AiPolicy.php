<?php

namespace App\Ai;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Settings → AI: which features may use a model, which model each uses, whether data may leave Qatar, whether personal data is redacted, how long logs are kept.
 */
class AiPolicy
{
    public const KEY = 'ai_policy';

    public const FEATURES = ['recommendations', 'feedback', 'adaptive', 'forecasts', 'assistant'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'residency_enforced' => true, 'redaction' => true, 'retention_days' => 30, 'log_prompts' => false,
            'features' => array_fill_keys(self::FEATURES, ['enabled' => true, 'model_id' => null, 'allow_external' => false]),
            'recommendations' => ['weights' => ['rules' => 40, 'gap' => 20, 'peers' => 20, 'behaviour' => 10, 'rating' => 10], 'ab_test' => false, 'ab_share' => 50],
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember('site.ai_policy', 30, function () {
            $saved = SiteSetting::find(self::KEY)?->value ?? [];
            $d = self::defaults();
            $out = array_replace($d, array_intersect_key($saved, $d));
            $out['features'] = [];
            foreach (self::FEATURES as $f) {
                $out['features'][$f] = array_replace($d['features'][$f], (array) ($saved['features'][$f] ?? []));
            }
            $out['recommendations'] = array_replace($d['recommendations'], (array) ($saved['recommendations'] ?? []));
            $out['recommendations']['weights'] = array_replace($d['recommendations']['weights'], (array) ($saved['recommendations']['weights'] ?? []));

            return $out;
        });
    }

    /** @return array{enabled: bool, model_id: ?string, allow_external: bool} */
    public function feature(string $feature): array
    {
        return $this->all()['features'][$feature] ?? ['enabled' => false, 'model_id' => null, 'allow_external' => false];
    }

    /** @param  array<string, mixed>  $in @return array<string, mixed> */
    public function save(array $in, ?string $by = null): array
    {
        $cur = $this->all();
        $features = [];
        foreach (self::FEATURES as $f) {
            $x = (array) ($in['features'][$f] ?? []);
            $features[$f] = [
                'enabled' => (bool) ($x['enabled'] ?? $cur['features'][$f]['enabled']),
                'model_id' => array_key_exists('model_id', $x) ? ($x['model_id'] ?: null) : $cur['features'][$f]['model_id'],
                'allow_external' => (bool) ($x['allow_external'] ?? $cur['features'][$f]['allow_external']),
            ];
        }
        $w = (array) ($in['recommendations']['weights'] ?? $cur['recommendations']['weights']);
        $weights = [];
        foreach (array_keys($cur['recommendations']['weights']) as $k) {
            $weights[$k] = max(0, min(100, (int) ($w[$k] ?? $cur['recommendations']['weights'][$k])));
        }
        $new = [
            'residency_enforced' => (bool) ($in['residency_enforced'] ?? $cur['residency_enforced']),
            'redaction' => (bool) ($in['redaction'] ?? $cur['redaction']),
            'retention_days' => max(1, min(365, (int) ($in['retention_days'] ?? $cur['retention_days']))),
            'log_prompts' => false,   // prompts are never stored: only sizes, timings and outcomes
            'features' => $features,
            'recommendations' => ['weights' => $weights, 'ab_test' => (bool) ($in['recommendations']['ab_test'] ?? $cur['recommendations']['ab_test']), 'ab_share' => max(0, min(100, (int) ($in['recommendations']['ab_share'] ?? $cur['recommendations']['ab_share'])))],
        ];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $new, 'updated_by' => $by]);
        Cache::forget('site.ai_policy');

        return $new;
    }
}
