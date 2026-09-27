# TEDC platform — single production image: React web app + Laravel API, served by FrankenPHP (Caddy).
# Used by Railway (railway.json) and any Docker host. Listens on $PORT (default 8080).

# ---- 1. Web app (React/Vite) -------------------------------------------------------------
FROM node:22-bookworm-slim AS web
WORKDIR /web
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
# Public build-time values (never secrets). Set them as Railway variables to enable Supabase Realtime.
ARG VITE_SUPABASE_URL=""
ARG VITE_SUPABASE_PUBLISHABLE_KEY=""
ENV VITE_API_URL=/api/v1 \
    VITE_SUPABASE_URL=$VITE_SUPABASE_URL \
    VITE_SUPABASE_PUBLISHABLE_KEY=$VITE_SUPABASE_PUBLISHABLE_KEY
RUN npm run build

# ---- 2. PHP dependencies ------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --ignore-platform-reqs

# ---- 3. Runtime ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm
RUN install-php-extensions pdo_pgsql pgsql intl gd zip bcmath opcache pcntl \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'memory_limit=512M\nupload_max_filesize=20M\npost_max_size=24M\nexpose_php=Off\nopcache.validate_timestamps=0\n' > "$PHP_INI_DIR/conf.d/zz-tedc.ini"

WORKDIR /app
COPY backend/ ./
COPY --from=vendor /app/vendor ./vendor
# The SPA lives next to the API: assets are served as static files, every other page gets app.html.
COPY --from=web /web/dist/ ./public/
RUN mv public/index.html public/app.html \
 && php artisan package:discover --ansi \
 && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/start.sh /usr/local/bin/tedc-start
RUN chmod +x /usr/local/bin/tedc-start

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=cookie \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    PORT=8080

EXPOSE 8080
CMD ["tedc-start"]
