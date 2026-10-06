<?php

namespace App\Ops;

use App\Services\FileStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Probes for the platform that hosts it (Azure Container Apps / Kubernetes): *live* says the process is up and should not be restarted;
 * *ready* says the instance may receive traffic — the database, the cache, the storage and the queue answer, and the queue is not falling behind.
 * Nothing here reveals hosts, users or settings.
 */
class HealthProbes
{
    public const QUEUE_LAG_WARN = 300;     // seconds the oldest waiting job may be old before the instance reports itself degraded

    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    public function ready(): array
    {
        $checks = [];
        foreach (['database' => fn () => $this->database(), 'cache' => fn () => $this->cache(), 'storage' => fn () => $this->storage(), 'queue' => fn () => $this->queue()] as $name => $probe) {
            $t = microtime(true);
            try {
                $checks[$name] = $probe() + ['ms' => (int) round((microtime(true) - $t) * 1000)];
            } catch (Throwable $e) {
                $checks[$name] = ['status' => 'fail', 'reason' => class_basename($e), 'ms' => (int) round((microtime(true) - $t) * 1000)];
            }
        }
        $fail = collect($checks)->contains(fn ($c) => $c['status'] === 'fail');
        $warn = collect($checks)->contains(fn ($c) => $c['status'] === 'degraded');

        return ['status' => $fail ? 'fail' : ($warn ? 'degraded' : 'ok'), 'checks' => $checks];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        DB::select('select 1');

        return ['status' => Schema::hasTable('users') ? 'ok' : 'fail', 'driver' => (string) config('database.default')];
    }

    /** @return array<string, mixed> */
    private function cache(): array
    {
        $key = 'health:'.bin2hex(random_bytes(4));
        Cache::put($key, '1', 10);
        $ok = Cache::get($key) === '1';
        Cache::forget($key);

        return ['status' => $ok ? 'ok' : 'fail', 'store' => (string) config('cache.default')];
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $driver = (string) config('tedc.storage_driver');
        $storage = app(FileStorage::class);
        $path = '_health/'.date('Ymd').'.txt';
        // A write and a read-back on every probe would be wasteful: the probe only reads a marker it wrote once.
        if (! Cache::get('health:storage-marker')) {
            $storage->put('documents', $path, 'ok', 'text/plain');
            Cache::put('health:storage-marker', 1, 3600);
        }

        return ['status' => $storage->exists('documents', $path) ? 'ok' : 'degraded', 'driver' => $driver];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return ['status' => 'ok', 'connection' => (string) config('queue.default')];
        }
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $lag = $oldest ? max(0, time() - (int) $oldest) : 0;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count() : 0;

        return ['status' => $lag > self::QUEUE_LAG_WARN ? 'degraded' : 'ok', 'lag_seconds' => $lag, 'failed_last_hour' => $failed, 'connection' => 'database'];
    }
}
