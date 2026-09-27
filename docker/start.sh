#!/bin/sh
# Container entrypoint: prepare Laravel, run the scheduler in the background, then serve.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
  echo "ERROR: APP_KEY is not set. Generate one with: php artisan key:generate --show  (then add it as a Railway variable)" >&2
  exit 1
fi

# Railway exposes the service domain; use it as the public URL unless set explicitly.
if [ -n "$RAILWAY_PUBLIC_DOMAIN" ]; then
  export APP_URL="${APP_URL:-https://$RAILWAY_PUBLIC_DOMAIN}"
  export TEDC_WEB_URL="${TEDC_WEB_URL:-https://$RAILWAY_PUBLIC_DOMAIN}"
  export CORS_ALLOWED_ORIGINS="${CORS_ALLOWED_ORIGINS:-https://$RAILWAY_PUBLIC_DOMAIN}"
fi

php artisan tedc:deploy

# Laravel scheduler (programme lifecycle, session reminders, impact surveys), restarted if it exits.
(while true; do php artisan schedule:work --quiet || true; sleep 5; done) &

exec frankenphp run --config /etc/frankenphp/Caddyfile
