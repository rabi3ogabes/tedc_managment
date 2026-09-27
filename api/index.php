<?php

/*
 * Vercel serverless entry for the Laravel API (vercel-php runtime).
 * vercel.json rewrites /api/*, /up and /files/* here; the React app is served statically from web/dist.
 *
 * Only /tmp is writable on Vercel, so storage and the framework caches live there, and defaults suited to
 * a stateless function are applied unless the project's environment variables say otherwise.
 */

$tmp = '/tmp/tedc';
foreach (['storage/app/mpdf', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/views', 'storage/logs', 'bootstrap'] as $dir) {
    if (! is_dir("{$tmp}/{$dir}")) {
        @mkdir("{$tmp}/{$dir}", 0775, true);
    }
}

$productionHost = getenv('VERCEL_PROJECT_PRODUCTION_URL') ?: getenv('VERCEL_URL');

$defaults = [
    'LARAVEL_STORAGE_PATH' => "{$tmp}/storage",
    'VIEW_COMPILED_PATH' => "{$tmp}/storage/framework/views",
    'APP_CONFIG_CACHE' => "{$tmp}/bootstrap/config.php",
    'APP_EVENTS_CACHE' => "{$tmp}/bootstrap/events.php",
    'APP_PACKAGES_CACHE' => "{$tmp}/bootstrap/packages.php",
    'APP_ROUTES_CACHE' => "{$tmp}/bootstrap/routes-v7.php",
    'APP_SERVICES_CACHE' => "{$tmp}/bootstrap/services.php",
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'LOG_CHANNEL' => 'stderr',
    'SESSION_DRIVER' => 'cookie',
    'CACHE_STORE' => 'database',
    'QUEUE_CONNECTION' => 'sync',
    'APP_URL' => $productionHost ? "https://{$productionHost}" : null,
];

foreach ($defaults as $key => $value) {
    if ($value !== null && getenv($key) === false && ! isset($_ENV[$key]) && ! isset($_SERVER[$key])) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

// Present the request to Laravel as if it hit public/index.php at the web root. Otherwise Symfony derives a
// "/api" base path from /api/index.php and strips it from /api/v1/... URLs.
$front = __DIR__.'/../backend/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = realpath($front);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = dirname((string) realpath($front));

require $front;
