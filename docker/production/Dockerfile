# EPFD production image: one image, three roles (web, worker, scheduler) chosen by the command.
#   docker build -f docker/production/Dockerfile -t epfd:latest .
#   web:       docker run epfd:latest                     (nginx + php-fpm on :8080)
#   worker:    docker run epfd:latest php artisan queue:work --tries=3 --max-time=3600
#   scheduler: docker run epfd:latest php artisan schedule:work
# Configuration comes from environment variables (see .env.production.example); no .env file is baked in.

# --- PHP dependencies -------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# --- Front-end build --------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
# Public build-time settings only (Vite inlines VITE_* into the bundle).
ARG VITE_SENTRY_DSN=""
ARG VITE_APP_ENV="production"
RUN npm run build

# --- Runtime ----------------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor postgresql-client curl gzip unzip \
        libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip gd intl bcmath pcntl exif opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
    && apt-get install -y --no-install-recommends libpq5 libzip4 libpng16-16 libjpeg62-turbo libfreetype6 libicu72 \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-epfd.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-epfd.conf
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/epfd.conf
COPY docker/production/entrypoint.sh /usr/local/bin/epfd-entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN rm -rf .env tests node_modules storage/app/private/* storage/logs/* \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/app/private storage/app/public storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/epfd-entrypoint

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["epfd-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/epfd.conf"]
