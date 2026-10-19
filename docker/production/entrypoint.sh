#!/bin/sh
# Prepares Laravel's caches from the container's environment, then runs the role's command.
# Set RUN_MIGRATIONS=true on exactly one service (usually the web role) to migrate on start.
set -e
cd /var/www/html

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

if [ ! -L public/storage ]; then
    php artisan storage:link --no-interaction || true
fi

exec "$@"
