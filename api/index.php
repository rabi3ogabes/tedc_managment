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

require __DIR__.'/../backend/scripts/vercel-env.php';
tedc_vercel_env();

// Present the request to Laravel as if it hit public/index.php at the web root. Otherwise Symfony derives a
// "/api" base path from /api/index.php and strips it from /api/v1/... URLs.
$front = __DIR__.'/../backend/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = realpath($front);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = dirname((string) realpath($front));

require $front;
