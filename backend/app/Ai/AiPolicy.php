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

    public const FEATURES = ['recommendations', 'feedback', 'adaptive', 'forecasts', 'assistant', 'surveys'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'residency_enforced' => true, 'redaction' => true, 'retention_days' => 30, 'log_prompts' => false,
            'features' => array_fill_keys(self::FEATURES, ['enabled' => true, 'model_id' => null, 'allow_external' => false]),
            'recommendations' => ['weights' => ['rules' => 40, 'gap' => 20, 'peers' => 20, 'behaviour' => 10, 'rating' => 10], 'ab_test' => false, 'ab_share' => 50],
            // Adaptive practice (percent of mastery): below easy_below draws easy, above hard_above draws hard; the level
            // goes up after up_after correct answers in a row and down after down_after wrong ones. A review lesson is
            // assigned below remedial_below and leaves the learner's path at mastered_at.
            'adaptive' => ['easy_below' => 40, 'hard_above' => 75, 'up_after' => 2, 'down_after' => 1, 'remedial_below' => 60, 'mastered_at' => 80],
            // Retrieval for the assistant: the store (auto picks pgvector where the database has it), the embedder in use
            // (set by a finished re-embedding run, never typed in) and the similarity a passage needs to count — and to be
            // a confident answer — for the local embedder and for an embeddings model, whose scores run higher.
            'retrieval' => ['store' => 'auto', 'active' => 'local', 'local' => ['min' => 0.1, 'strong' => 0.2], 'model' => ['min' => 0.3, 'strong' => 0.45]],
            // The golden question set must reach these shares for the assistant to pass its evaluation.
            'eval' => ['hit_at_3' => 0.75, 'hit_at_5' => 0.85, 'citation' => 0.7, 'refusal' => 0.75],
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
            $out['adaptive'] = array_map('intval', array_replace($d['adaptive'], array_intersect_key((array) ($saved['adaptive'] ?? []), $d['adaptive'])));
            $r = (array) ($saved['retrieval'] ?? []);
            $out['retrieval'] = ['store' => (string) ($r['store'] ?? 'auto'), 'active' => (string) ($r['active'] ?? 'local'),
                'local' => array_map('floatval', array_replace($d['retrieval']['local'], array_intersect_key((array) ($r['local'] ?? []), $d['retrieval']['local']))),
                'model' => array_map('floatval', array_replace($d['retrieval']['model'], array_intersect_key((array) ($r['model'] ?? []), $d['retrieval']['model'])))];
            $out['eval'] = array_map('floatval', array_replace($d['eval'], array_intersect_key((array) ($saved['eval'] ?? []), $d['eval'])));

            return $out;
        });
    }

    /** @return array{enabled: bool, model_id: ?string, allow_external: bool} */
    public function feature(string $feature): array
    {
        return $this->all()['features'][$feature] ?? ['enabled' => false, 'model_id' => null, 'allow_external' => false];
    }

    /** @return array{easy_below: int, hard_above: int, up_after: int, down_after: int, remedial_below: int, mastered_at: int} */
    public function adaptive(): array
    {
        return $this->all()['adaptive'];
    }

    /** Switches retrieval to the embedder a finished run filled (local, or an embeddings model id). */
    public function activate(string $embedder): void
    {
        $saved = SiteSetting::find(self::KEY)?->value ?? [];
        $saved['retrieval'] = array_replace((array) ($saved['retrieval'] ?? []), ['active' => $embedder]);
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $saved]);
        Cache::forget('site.ai_policy');
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
        $a = array_replace($cur['adaptive'], array_intersect_key((array) ($in['adaptive'] ?? []), $cur['adaptive']));
        $easy = max(0, min(100, (int) $a['easy_below']));
        $remedial = max(0, min(100, (int) $a['remedial_below']));
        $adaptive = [
            'easy_below' => $easy, 'hard_above' => max($easy, min(100, (int) $a['hard_above'])),
            'up_after' => max(1, min(10, (int) $a['up_after'])), 'down_after' => max(1, min(10, (int) $a['down_after'])),
            // A lesson is never assigned and cleared at the same mastery.
            'remedial_below' => $remedial, 'mastered_at' => max($remedial, min(100, (int) $a['mastered_at'])),
        ];
        $share = fn ($v, $cur) => max(0.0, min(1.0, (float) ($v ?? $cur)));
        $rin = (array) ($in['retrieval'] ?? []);
        $retrieval = ['store' => in_array($rin['store'] ?? null, ['auto', 'json', 'pgvector'], true) ? $rin['store'] : $cur['retrieval']['store'], 'active' => $cur['retrieval']['active']];
        foreach (['local', 'model'] as $kind) {
            $min = $share($rin[$kind]['min'] ?? null, $cur['retrieval'][$kind]['min']);
            $retrieval[$kind] = ['min' => $min, 'strong' => max($min, $share($rin[$kind]['strong'] ?? null, $cur['retrieval'][$kind]['strong']))];
        }
        $eval = [];
        foreach ($cur['eval'] as $k => $v) {
            $eval[$k] = $share($in['eval'][$k] ?? null, $v);
        }
        $new = [
            'residency_enforced' => (bool) ($in['residency_enforced'] ?? $cur['residency_enforced']),
            'redaction' => (bool) ($in['redaction'] ?? $cur['redaction']),
            'retention_days' => max(1, min(365, (int) ($in['retention_days'] ?? $cur['retention_days']))),
            'log_prompts' => false,   // prompts are never stored: only sizes, timings and outcomes
            'features' => $features,
            'recommendations' => ['weights' => $weights, 'ab_test' => (bool) ($in['recommendations']['ab_test'] ?? $cur['recommendations']['ab_test']), 'ab_share' => max(0, min(100, (int) ($in['recommendations']['ab_share'] ?? $cur['recommendations']['ab_share'])))],
            'adaptive' => $adaptive,
            'retrieval' => $retrieval,
            'eval' => $eval,
        ];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $new, 'updated_by' => $by]);
        Cache::forget('site.ai_policy');

        return $new;
    }
}
