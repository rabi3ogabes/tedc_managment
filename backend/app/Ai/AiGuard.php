<?php

namespace App\Ai;

use App\Models\AiLog;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiModelSettings;
use App\Services\FeatureSettings;

/**
 * Every AI call of the platform goes through here: the feature must be on, the model must be allowed to see the data (residency),
 * personal identifiers are redacted on the way out and restored on the way back, and a log line records the outcome (never the prompt).
 * When anything stops the call the caller receives null and uses its rule-based behaviour.
 */
class AiGuard
{
    public function __construct(private readonly AiGateway $gateway, private readonly AiModelSettings $models, private readonly AiPolicy $policy, private readonly FeatureSettings $features) {}

    /** Whether a model could answer for this feature right now (feature on, a model assigned, and allowed to see personal data when it is going to). */
    public function available(string $feature, bool $personal = true): bool
    {
        return $this->check($feature, $personal)['resolved'] !== null;
    }

    /**
     * @param  list<string>  $names  names to hide from the prompt
     * @return array{text: string, redactions: int}|null
     */
    public function text(string $feature, string $system, array|string $messages, ?User $user = null, bool $personal = true, array $names = [], ?int $maxTokens = null): ?array
    {
        $gate = $this->check($feature, $personal);
        if (! $gate['resolved']) {
            $this->log($feature, $user, $gate['status'], $gate['reason'], null, 0, 0, 0, 0);

            return null;
        }
        $r = $gate['resolved'];
        $redact = $personal && $this->policy->all()['redaction'];
        $red = new Redactor($names);
        $msgs = is_string($messages) ? [['role' => 'user', 'content' => $messages]] : $messages;
        if ($redact) {
            $system = $red->redact($system);
            $msgs = array_map(fn ($m) => ['role' => $m['role'], 'content' => $red->redact((string) $m['content'])], $msgs);
        }
        $chars = mb_strlen($system) + array_sum(array_map(fn ($m) => mb_strlen((string) $m['content']), $msgs));
        $start = microtime(true);
        $out = $this->gateway->complete($r, $system, $msgs, $maxTokens);
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($out === null) {
            $this->log($feature, $user, 'failed', 'call_failed', $r, $chars, 0, $ms, $red->count());

            return null;
        }
        $this->log($feature, $user, 'ok', null, $r, $chars, mb_strlen($out), $ms, $red->count());

        return ['text' => $redact ? $red->restore($out) : $out, 'redactions' => $red->count()];
    }

    /** JSON answer under the same rules. @param  array<string, mixed>  $schema @param  list<string>  $names @return array<string, mixed>|null */
    public function json(string $feature, string $system, string $prompt, array $schema, ?User $user = null, bool $personal = true, array $names = []): ?array
    {
        $system .= "\n\nReply with ONE JSON object only, no commentary and no code fences, matching this JSON Schema:\n".json_encode($schema, JSON_UNESCAPED_UNICODE);
        $res = $this->text($feature, $system, $prompt, $user, $personal, $names);

        return $res ? AiGateway::decode($res['text']) : null;
    }

    /** @return array{resolved: ?array, status: string, reason: ?string} */
    private function check(string $feature, bool $personal): array
    {
        $f = $this->policy->feature($feature);
        if (! $this->features->enabled('ai') || ! $f['enabled']) {
            return ['resolved' => null, 'status' => 'blocked', 'reason' => 'feature_off'];
        }
        $resolved = $f['model_id'] ? $this->models->resolveModel($f['model_id']) : null;
        $resolved ??= $this->models->resolve('text');
        if (! $resolved) {
            return ['resolved' => null, 'status' => 'fallback', 'reason' => 'no_model'];
        }
        $residency = $resolved['connection']['data_residency'] ?? 'external';
        if ($personal && $this->policy->all()['residency_enforced'] && $residency === 'external' && ! $f['allow_external']) {
            return ['resolved' => null, 'status' => 'blocked', 'reason' => 'residency'];
        }

        return ['resolved' => $resolved, 'status' => 'ok', 'reason' => null];
    }

    private function log(string $feature, ?User $user, string $status, ?string $reason, ?array $r, int $chars, int $outChars, int $ms, int $redactions): void
    {
        try {
            AiLog::create(['feature' => $feature, 'user_id' => $user?->id, 'connection_id' => $r['connection']['id'] ?? null, 'model' => $r['model']['model'] ?? null, 'status' => $status, 'reason' => $reason,
                'residency' => $r['connection']['data_residency'] ?? null, 'prompt_chars' => $chars, 'tokens_in' => (int) ceil($chars / 4), 'tokens_out' => (int) ceil($outChars / 4), 'latency_ms' => $ms, 'redactions' => $redactions, 'created_at' => now()]);
        } catch (\Throwable) {
            // logging must never break a request
        }
    }

    /** Logs older than the retention period are deleted. */
    public function prune(): int
    {
        return AiLog::where('created_at', '<', now()->subDays((int) $this->policy->all()['retention_days']))->delete();
    }
}
