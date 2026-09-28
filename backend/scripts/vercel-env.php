<?php

/*
 * Environment defaults for Vercel (used by api/index.php at runtime and scripts/vercel-build.php at build time).
 * Values set in Vercel → Settings → Environment Variables always win; only missing ones get a default.
 * Only public, non-secret values live here — secrets (APP_KEY, DB_URL, SUPABASE_SECRET_KEY, CRON_SECRET) must
 * be set in Vercel.
 */

function tedc_vercel_env(bool $runtime = true): void
{
    $tmp = '/tmp/tedc';
    // Framework caches prebuilt during the Vercel build (read-only in the function); /tmp otherwise.
    $bundled = dirname(__DIR__).'/bootstrap/cache';
    $cache = fn (string $file) => ($runtime && is_file("{$bundled}/{$file}")) ? "{$bundled}/{$file}" : "{$tmp}/bootstrap/{$file}";
    $productionHost = getenv('VERCEL_PROJECT_PRODUCTION_URL') ?: getenv('VERCEL_URL');

    $defaults = [
        // Stateless serverless function: storage and framework caches in /tmp (the only writable folder).
        'LARAVEL_STORAGE_PATH' => $runtime ? "{$tmp}/storage" : null,
        'VIEW_COMPILED_PATH' => $runtime ? "{$tmp}/storage/framework/views" : null,
        'APP_CONFIG_CACHE' => "{$tmp}/bootstrap/config.php",
        'APP_EVENTS_CACHE' => $cache('events.php'),
        'APP_PACKAGES_CACHE' => $cache('packages.php'),
        'APP_ROUTES_CACHE' => $cache('routes-v7.php'),
        'APP_SERVICES_CACHE' => $cache('services.php'),
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'LOG_CHANNEL' => 'stderr',
        'SESSION_DRIVER' => 'cookie',
        // In-memory per-instance cache (APCu) at runtime; nothing to cache during the build.
        'CACHE_STORE' => $runtime ? (extension_loaded('apcu') ? 'apc' : 'file') : 'array',
        // Reuse database connections across requests of a warm instance (no TLS/auth handshake each time).
        'DB_PERSISTENT' => 'true',
        'QUEUE_CONNECTION' => 'sync',
        'APP_URL' => $productionHost ? "https://{$productionHost}" : null,

        // Supabase project (public values).
        'DB_CONNECTION' => getenv('DB_URL') ? 'pgsql' : null,
        'DB_EMULATE_PREPARES' => 'true',
        'TEDC_AUTH_DRIVER' => 'supabase',
        'TEDC_STORAGE_DRIVER' => 'supabase',
        'SUPABASE_URL' => 'https://wlgvhrmdooembvvoqjnc.supabase.co',
        'SUPABASE_PUBLISHABLE_KEY' => 'sb_publishable_gp-BK8p_bdJf1BrIckE45Q_fvxjiO8b',
        'SUPABASE_JWKS_URL' => 'https://wlgvhrmdooembvvoqjnc.supabase.co/auth/v1/.well-known/jwks.json',
        'TEDC_SEED_DEMO' => 'true',
    ];

    foreach ($defaults as $key => $value) {
        if ($value !== null && getenv($key) === false && ! isset($_ENV[$key]) && ! isset($_SERVER[$key])) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    // Serverless functions open many short connections: use Supabase's transaction pooler (port 6543)
    // even when the session-pooler URI (port 5432) was pasted.
    $url = getenv('DB_URL');
    if ($url && preg_match('#\.pooler\.supabase\.com:5432/#', $url)) {
        $url = preg_replace('#(\.pooler\.supabase\.com):5432/#', '$1:6543/', $url);
        putenv("DB_URL={$url}");
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = $url;
    }
    if ($url && str_contains($url, 'supabase.co') && ! str_contains($url, 'sslmode=')) {
        $url .= (str_contains($url, '?') ? '&' : '?').'sslmode=require';
        putenv("DB_URL={$url}");
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = $url;
    }
}
