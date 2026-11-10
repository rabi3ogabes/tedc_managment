<?php

/*
 * Vercel build step (root composer.json "vercel" script): prepares the database of the deployment —
 * migrations, first-time seeding (demo data when TEDC_SEED_DEMO=true) and the demo accounts in Supabase Auth —
 * so no manual setup call is needed. Never fails the build: problems are reported and /api/v1/public/health
 * explains them after deployment.
 */

require __DIR__.'/vercel-env.php';
tedc_vercel_env(runtime: false);
@mkdir('/tmp/tedc/bootstrap', 0775, true);

$artisan = escapeshellarg(__DIR__.'/../artisan');
$php = escapeshellarg(PHP_BINARY);

// Prebuild the framework caches into backend/bootstrap/cache; the function reads them instead of rebuilding
// them on every cold start. Configuration is not cached: it depends on runtime variables.
$bundled = dirname(__DIR__).'/bootstrap/cache';
foreach (['APP_PACKAGES_CACHE' => 'packages.php', 'APP_SERVICES_CACHE' => 'services.php', 'APP_ROUTES_CACHE' => 'routes-v7.php', 'APP_EVENTS_CACHE' => 'events.php'] as $key => $file) {
    putenv("{$key}={$bundled}/{$file}");
}
echo '→ Prebuilding framework caches…'.PHP_EOL;
passthru("{$php} {$artisan} package:discover --no-interaction 2>&1");
passthru("{$php} {$artisan} route:cache --no-interaction 2>&1");
passthru("{$php} {$artisan} event:cache --no-interaction 2>&1");

$missing = array_values(array_filter(['APP_KEY', 'DB_URL'], fn ($k) => ! getenv($k)));
if ($missing) {
    echo '⚠ Database setup skipped — set in Vercel → Settings → Environment Variables: '.implode(', ', $missing).PHP_EOL;
    exit(0);
}

echo '→ Preparing the database (migrations, first-time seeding)…'.PHP_EOL;
passthru("{$php} {$artisan} tedc:deploy --no-cache --no-interaction 2>&1", $status);
if ($status !== 0) {
    echo '⚠ Database setup failed (see above). The deployment continues; check /api/v1/public/health.'.PHP_EOL;
    exit(0);
}

// Passwords are never set by a deployment: accounts keep the password their owner chose (demo accounts are created only
// outside production, by the seeders).
exit(0);
