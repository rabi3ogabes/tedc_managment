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

if (getenv('SUPABASE_SECRET_KEY') && filter_var(getenv('TEDC_SEED_DEMO'), FILTER_VALIDATE_BOOL)) {
    echo '→ Creating the demo accounts in Supabase Auth (existing accounts keep their password)…'.PHP_EOL;
    passthru("{$php} {$artisan} tedc:supabase-sync-users --password=".escapeshellarg('Tedc@2026!').' --no-interaction 2>&1');
}

exit(0);
