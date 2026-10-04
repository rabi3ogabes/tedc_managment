<?php

namespace App\Services\Ai;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * The AI models the platform may use. A *connection* is an account at a provider (OpenRouter, OpenAI, Anthropic or any
 * OpenAI-compatible address) with its API key; a *model* is one model of a connection and the kinds of work it is for;
 * an *assignment* says which model does each kind of work (content, images, sound, video). Keys are stored encrypted and
 * never leave the server. Where nothing is assigned the platform keeps working as before.
 */
class AiModelSettings
{
    public const KEY = 'ai_models';

    public const TASKS = ['text', 'image', 'audio', 'video'];

    public const DRIVERS = [
        'openrouter' => 'https://openrouter.ai/api/v1',
        'openai' => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com',
        'custom' => null,
    ];

    public static function defaults(): array
    {
        return ['connections' => [], 'models' => [], 'assignments' => array_fill_keys(self::TASKS, null), 'secrets' => null];
    }

    public function all(): array
    {
        return Cache::remember('site.ai_models', 30, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    /** What the settings page may see: no keys, only whether one is set and its last characters. */
    public function forAdmin(): array
    {
        $all = $this->all();
        $secrets = $this->secrets();

        return [
            'connections' => array_map(fn (array $c) => Arr::except($c, ['id']) + ['id' => $c['id'], 'has_key' => filled($secrets[$c['id']] ?? null), 'key_hint' => filled($secrets[$c['id']] ?? null) ? '••••'.substr($secrets[$c['id']], -4) : null], $all['connections']),
            'models' => $all['models'],
            'assignments' => $all['assignments'] + array_fill_keys(self::TASKS, null),
            'drivers' => array_map(fn ($url) => ['base_url' => $url], self::DRIVERS),
        ];
    }

    /** @return array<string, string> connection id => API key */
    private function secrets(): array
    {
        $encrypted = $this->all()['secrets'];
        if (! is_string($encrypted) || $encrypted === '') {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($encrypted), true) ?: [];
        } catch (Throwable) {
            return [];   // APP_KEY changed: the keys must be entered again
        }
    }

    public function connection(string $id): ?array
    {
        $c = collect($this->all()['connections'])->firstWhere('id', $id);

        return $c ? $c + ['base_url_resolved' => rtrim((string) ($c['base_url'] ?: (self::DRIVERS[$c['driver']] ?? '')), '/')] : null;
    }

    public function key(string $connectionId): ?string
    {
        return $this->secrets()[$connectionId] ?? null;
    }

    public function model(string $id): ?array
    {
        return collect($this->all()['models'])->firstWhere('id', $id);
    }

    /**
     * The model that does a kind of work, ready to call: the model, its connection and the key. Null when nothing usable
     * is assigned (no assignment, switched off, or no key yet), so callers fall back to what they did before.
     *
     * @return array{model: array<string, mixed>, connection: array<string, mixed>, key: string}|null
     */
    public function resolve(string $task): ?array
    {
        $id = $this->all()['assignments'][$task] ?? null;
        $model = $id ? $this->model($id) : null;
        if (! $model || ! ($model['enabled'] ?? true) || ! in_array($task, $model['tasks'] ?? [], true)) {
            return null;
        }
        $connection = $this->connection($model['connection_id']);
        $key = $connection ? $this->key($connection['id']) : null;

        return $connection && ($connection['enabled'] ?? true) && filled($key) ? ['model' => $model, 'connection' => $connection, 'key' => $key] : null;
    }

    // Changes ---------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $in  name, driver, base_url, enabled, api_key (blank = keep) */
    public function saveConnection(array $in, ?string $id = null, ?User $by = null): array
    {
        $all = $this->all();
        $id ??= Str::lower(Str::random(8));
        $existing = collect($all['connections'])->firstWhere('id', $id);
        $driver = in_array($in['driver'] ?? $existing['driver'] ?? null, array_keys(self::DRIVERS), true) ? ($in['driver'] ?? $existing['driver']) : 'openrouter';
        $row = [
            'id' => $id, 'name' => mb_substr(trim((string) ($in['name'] ?? $existing['name'] ?? ucfirst($driver))), 0, 80), 'driver' => $driver,
            'base_url' => $driver === 'custom' ? mb_substr(rtrim((string) ($in['base_url'] ?? $existing['base_url'] ?? ''), '/'), 0, 300) : null,
            'enabled' => (bool) ($in['enabled'] ?? $existing['enabled'] ?? true),
        ];
        $all['connections'] = $existing ? array_map(fn ($c) => $c['id'] === $id ? $row : $c, $all['connections']) : [...$all['connections'], $row];

        $secrets = $this->secrets();
        if (filled($in['api_key'] ?? null)) {
            $secrets[$id] = trim((string) $in['api_key']);
        }
        if (! empty($in['clear_key'])) {
            unset($secrets[$id]);
        }
        $all['secrets'] = $secrets ? Crypt::encryptString(json_encode($secrets)) : null;

        $this->write($all, $by);

        return $row;
    }

    public function deleteConnection(string $id, ?User $by = null): void
    {
        $all = $this->all();
        $gone = collect($all['models'])->where('connection_id', $id)->pluck('id')->all();
        $all['connections'] = array_values(array_filter($all['connections'], fn ($c) => $c['id'] !== $id));
        $all['models'] = array_values(array_filter($all['models'], fn ($m) => $m['connection_id'] !== $id));
        $all['assignments'] = array_map(fn ($a) => in_array($a, $gone, true) ? null : $a, $all['assignments']);
        $secrets = $this->secrets();
        unset($secrets[$id]);
        $all['secrets'] = $secrets ? Crypt::encryptString(json_encode($secrets)) : null;
        $this->write($all, $by);
    }

    /** @param  array<string, mixed>  $in  connection_id, model, label, tasks, enabled, params */
    public function saveModel(array $in, ?string $id = null, ?User $by = null): array
    {
        $all = $this->all();
        $id ??= Str::lower(Str::random(8));
        $existing = collect($all['models'])->firstWhere('id', $id);
        $params = (array) ($in['params'] ?? $existing['params'] ?? []);
        $row = [
            'id' => $id, 'connection_id' => (string) ($in['connection_id'] ?? $existing['connection_id']), 'model' => mb_substr(trim((string) ($in['model'] ?? $existing['model'] ?? '')), 0, 200),
            'label' => mb_substr(trim((string) ($in['label'] ?? $existing['label'] ?? '')), 0, 100),
            'tasks' => array_values(array_intersect(self::TASKS, (array) ($in['tasks'] ?? $existing['tasks'] ?? ['text']))),
            'enabled' => (bool) ($in['enabled'] ?? $existing['enabled'] ?? true),
            'params' => [
                'temperature' => isset($params['temperature']) && $params['temperature'] !== '' ? max(0, min(2, (float) $params['temperature'])) : null,
                'max_tokens' => isset($params['max_tokens']) && $params['max_tokens'] !== '' ? max(16, min(200000, (int) $params['max_tokens'])) : null,
                'voice' => isset($params['voice']) ? mb_substr((string) $params['voice'], 0, 40) : null,
            ],
            'last_test' => $existing['last_test'] ?? null,
        ];
        $all['models'] = $existing ? array_map(fn ($m) => $m['id'] === $id ? $row : $m, $all['models']) : [...$all['models'], $row];
        // A model that no longer does a kind of work cannot stay assigned to it.
        foreach ($all['assignments'] as $task => $assigned) {
            if ($assigned === $id && ! in_array($task, $row['tasks'], true)) {
                $all['assignments'][$task] = null;
            }
        }
        $this->write($all, $by);

        return $row;
    }

    public function deleteModel(string $id, ?User $by = null): void
    {
        $all = $this->all();
        $all['models'] = array_values(array_filter($all['models'], fn ($m) => $m['id'] !== $id));
        $all['assignments'] = array_map(fn ($a) => $a === $id ? null : $a, $all['assignments']);
        $this->write($all, $by);
    }

    /** @param  array<string, ?string>  $assignments */
    public function assign(array $assignments, ?User $by = null): void
    {
        $all = $this->all();
        foreach (self::TASKS as $task) {
            if (! array_key_exists($task, $assignments)) {
                continue;
            }
            $id = $assignments[$task];
            $model = $id ? $this->model($id) : null;
            $all['assignments'][$task] = $model && in_array($task, $model['tasks'], true) ? $id : null;
        }
        $this->write($all, $by);
    }

    public function recordTest(string $modelId, array $result): void
    {
        $all = $this->all();
        $all['models'] = array_map(function ($m) use ($modelId, $result) {
            if ($m['id'] === $modelId) {
                $m['last_test'] = ['ok' => (bool) $result['ok'], 'ms' => $result['ms'] ?? null, 'at' => now()->toIso8601String(), 'error' => $result['error'] ?? null];
            }

            return $m;
        }, $all['models']);
        $this->write($all);
    }

    private function write(array $all, ?User $by = null): bool
    {
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $all, 'updated_by' => $by?->id]);
        Cache::forget('site.ai_models');

        return true;
    }
}
