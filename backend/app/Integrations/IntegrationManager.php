<?php

namespace App\Integrations;

use App\Models\Integration;
use App\Models\IntegrationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One place that knows every connected system: its settings (encrypted), health, a circuit breaker and a log of every call.
 * `call()` wraps an outgoing operation: it retries once with a short back-off, records the outcome (without personal data) and opens the breaker
 * after five failures in a row, so a down system stops being hammered and the administrators are told.
 */
class IntegrationManager
{
    private const BREAKER_AFTER = 5;

    private const BREAKER_MINUTES = 5;

    public function __construct(private readonly NotificationService $notifications) {}

    public function get(string $key): Integration
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]), 404);

        return Integration::firstOrCreate(['key' => $key], ['driver' => IntegrationRegistry::all()[$key]['drivers'][0], 'enabled' => false]);
    }

    /** Settings with their defaults filled in. @return array<string, mixed> */
    public function settings(string $key): array
    {
        $defaults = collect(IntegrationRegistry::all()[$key]['fields'] ?? [])->filter(fn ($f) => array_key_exists('default', $f))->mapWithKeys(fn ($f) => [$f['k'] => $f['default']])->all();

        return array_replace($defaults, $this->get($key)->settings());
    }

    public function isReady(string $key): bool
    {
        $i = $this->get($key);

        return $i->enabled && ($i->driver === 'fake' || ! empty($this->settings($key)));
    }

    /** What an administrator sees: secrets replaced by "is it set". @return array<string, mixed> */
    public function present(string $key): array
    {
        $def = IntegrationRegistry::all()[$key];
        $i = $this->get($key);
        $settings = $this->settings($key);
        $secrets = IntegrationRegistry::secretFields($key);
        $out = Arr::except($settings, $secrets);
        $since = now()->subDay();

        return [
            'key' => $key, 'name' => $def['name'], 'group' => $def['group'], 'drivers' => $def['drivers'], 'fields' => $def['fields'], 'managed_elsewhere' => $def['managed_elsewhere'] ?? null,
            'driver' => $i->driver, 'enabled' => $i->enabled, 'health' => $i->health, 'latency_ms' => $i->latency_ms, 'last_check_at' => $i->last_check_at?->toIso8601String(), 'last_sync_at' => $i->last_sync_at?->toIso8601String(),
            'last_error' => $i->last_error, 'circuit_open' => (bool) $i->open_until?->isFuture(), 'settings' => $out, 'secrets_set' => collect($secrets)->mapWithKeys(fn ($k) => [$k => filled($settings[$k] ?? null)])->all(),
            'stats' => ['ok' => IntegrationLog::where('integration_key', $key)->where('status', 'ok')->where('created_at', '>=', $since)->count(), 'error' => IntegrationLog::where('integration_key', $key)->where('status', 'error')->where('created_at', '>=', $since)->count()],
        ];
    }

    /** @param  array<string, mixed>  $input  driver, enabled, settings (a blank secret keeps the stored one) */
    public function update(string $key, array $input): array
    {
        $def = IntegrationRegistry::all()[$key] ?? abort(404);
        $i = $this->get($key);
        if (isset($input['driver']) && in_array($input['driver'], $def['drivers'], true)) {
            $i->driver = $input['driver'];
        }
        if (array_key_exists('enabled', $input)) {
            $i->enabled = (bool) $input['enabled'];
        }
        if (isset($input['settings']) && is_array($input['settings'])) {
            $cur = $i->settings();
            $secrets = IntegrationRegistry::secretFields($key);
            $allowed = array_column($def['fields'], 'k');
            foreach (Arr::only($input['settings'], $allowed) as $k => $v) {
                if (in_array($k, $secrets, true) && ($v === null || $v === '')) {
                    continue;   // keep the stored secret
                }
                $cur[$k] = is_string($v) ? trim($v) : $v;
            }
            $i->putSettings($cur);
        }
        if (! empty($input['clear'])) {
            $cur = $i->settings();
            foreach ((array) $input['clear'] as $k) {
                if (in_array($k, IntegrationRegistry::secretFields($key), true)) {
                    unset($cur[$k]);
                }
            }
            $i->putSettings($cur);
        }
        // A changed setup starts with a clean breaker.
        $i->forceFill(['failures' => 0, 'open_until' => null, 'health' => 'unknown'])->save();

        return $this->present($key);
    }

    /**
     * Runs an outgoing operation against a system.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>, Integration): T  $fn  receives the settings
     * @param  array<string, mixed>  $request  a summary for the log, without personal data
     * @return T
     */
    public function call(string $key, string $operation, callable $fn, array $request = [], int $retries = 1): mixed
    {
        $i = $this->get($key);
        if (! $i->enabled) {
            throw new IntegrationUnavailable("{$key} is switched off.", 'disabled');
        }
        if ($i->open_until?->isFuture()) {
            $this->log($key, 'out', $operation, 'error', 0, $request, null, 'circuit_open', null);
            throw new IntegrationUnavailable("{$key} is paused after repeated failures.", 'circuit_open');
        }

        $corr = (string) Str::uuid();
        $settings = $this->settings($key);
        $last = null;
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $t0 = microtime(true);
            try {
                $result = $fn($settings, $i);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $this->log($key, 'out', $operation, 'ok', $ms, $request, is_scalar($result) || is_array($result) ? $this->summarise($result) : null, null, $corr);
                $i->forceFill(['failures' => 0, 'open_until' => null, 'health' => 'ok', 'latency_ms' => $ms, 'last_error' => null])->saveQuietly();

                return $result;
            } catch (Throwable $e) {
                $last = $e;
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $this->log($key, 'out', $operation, 'error', $ms, $request, null, mb_substr($e->getMessage(), 0, 400), $corr);
                if ($attempt < $retries && ($backoff = (int) config('tedc.integrations.backoff_ms', 300)) > 0) {
                    usleep($backoff * 1000 * ($attempt + 1));
                }
            }
        }

        $i->refresh();
        $failures = $i->failures + 1;
        $open = $failures >= self::BREAKER_AFTER;
        $i->forceFill(['failures' => $failures, 'health' => $open ? 'down' : 'degraded', 'last_error' => mb_substr($last->getMessage(), 0, 400), 'open_until' => $open ? now()->addMinutes(self::BREAKER_MINUTES) : $i->open_until])->saveQuietly();
        if ($open && Cache::add("integration.down.{$key}", true, now()->addHours(6))) {
            $this->alertDown($key, $last->getMessage());
        }

        throw $last;
    }

    /** A health check of one system (also stored). @return array{health: string, latency_ms: ?int, error: ?string} */
    public function check(string $key): array
    {
        $i = $this->get($key);
        $t0 = microtime(true);
        $error = null;
        try {
            if ($i->driver === 'fake') {
                $health = 'ok';
            } elseif (! $this->isReady($key)) {
                $health = 'unknown';
                $error = $i->enabled ? 'not_configured' : 'disabled';
            } else {
                $probe = $this->probe($key, $this->settings($key), $i->driver);
                $health = $probe;
            }
        } catch (Throwable $e) {
            $health = 'down';
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($health === 'ok' && $ms > 4000) {
            $health = 'degraded';
        }
        $i->forceFill(['health' => $health, 'latency_ms' => $ms, 'last_check_at' => now(), 'last_error' => $error ?? $i->last_error])->saveQuietly();
        if ($health === 'ok') {
            $i->forceFill(['failures' => 0, 'open_until' => null, 'last_error' => null])->saveQuietly();
        }

        return ['health' => $health, 'latency_ms' => $ms, 'error' => $error];
    }

    public function markSynced(string $key): void
    {
        $this->get($key)->forceFill(['last_sync_at' => now()])->saveQuietly();
    }

    /** Records a call that came in (a webhook). @param  array<string, mixed>  $request */
    public function logInbound(string $key, string $operation, string $status, array $request = [], ?string $error = null): void
    {
        $this->log($key, 'in', $operation, $status, 0, $request, null, $error, null);
    }

    /** Old log rows go after the retention period. */
    public function prune(): int
    {
        return IntegrationLog::where('created_at', '<', now()->subDays((int) config('tedc.integrations.log_days', 30)))->delete();
    }

    // ---- internals ------------------------------------------------------------------------------------

    /** The actual probe: an HTTP system answers its health path; identity systems answer their own way. */
    private function probe(string $key, array $s, string $driver): string
    {
        if ($key === 'entra') {
            $r = Http::timeout(8)->get('https://login.microsoftonline.com/'.($s['tenant_id'] ?? 'common').'/v2.0/.well-known/openid-configuration');

            return $r->successful() ? 'ok' : 'down';
        }
        if ($key === 'teams' && class_exists(\App\Integrations\Teams\GraphClient::class)) {
            return app(\App\Integrations\Teams\GraphClient::class)->ping($s) ? 'ok' : 'down';
        }
        if ($key === 'ldap' && class_exists(\App\Integrations\Identity\LdapDirectory::class)) {
            return app(\App\Integrations\Identity\LdapDirectory::class)->ping($s) ? 'ok' : 'down';
        }
        $base = rtrim((string) ($s['base_url'] ?? ''), '/');
        if ($base === '') {
            return 'unknown';
        }
        $r = Http::timeout(8)->when(filled($s['api_key'] ?? null), fn ($h) => $h->withToken((string) $s['api_key']))->get($base.'/'.ltrim((string) ($s['health_path'] ?? '/health'), '/'));

        return $r->successful() ? 'ok' : ($r->serverError() ? 'down' : 'degraded');
    }

    private function log(string $key, string $direction, string $operation, string $status, int $ms, array $request, mixed $response, ?string $error, ?string $corr): void
    {
        IntegrationLog::create(['integration_key' => $key, 'direction' => $direction, 'operation' => mb_substr($operation, 0, 80), 'status' => $status, 'duration_ms' => $ms,
            'request_summary' => $this->summarise($request), 'response_summary' => $response === null ? null : $this->summarise($response), 'error' => $error, 'correlation_id' => $corr, 'created_at' => now()]);
    }

    /** Short strings only, secrets and obvious personal fields removed. */
    private function summarise(mixed $v): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach (array_slice($v, 0, 20, true) as $k => $x) {
                $out[$k] = preg_match('/pass|secret|token|key|national|email|phone|authorization/i', (string) $k) ? '•••' : $this->summarise($x);
            }

            return $out;
        }

        return is_string($v) ? mb_substr($v, 0, 200) : $v;
    }

    private function alertDown(string $key, string $error): void
    {
        $admins = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::SUPER_ADMIN, Role::CENTER_ADMIN]))->pluck('id');
        $name = IntegrationRegistry::all()[$key]['name'];
        $this->notifications->broadcast($admins, 'integration.down', ['ar' => 'تعذّر الاتصال بنظام: '.$name['ar'], 'en' => 'Cannot reach: '.$name['en']],
            ['ar' => 'توقف الاتصال مؤقتًا بعد إخفاقات متتالية. '.mb_substr($error, 0, 120), 'en' => 'Calls are paused after repeated failures. '.mb_substr($error, 0, 120)], ['route' => '/admin/integrations'], raw: true);
    }
}
