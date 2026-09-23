#!/bin/sh
set -e

cd /app

# Only the web container runs migrations and warms caches; the scheduler just reuses them.
if [ "${POS_ROLE:-web}" = "web" ]; then
    php artisan migrate --force --no-interaction
    php artisan storage:link >/dev/null 2>&1 || true
    # No config:cache — Scramble's security scheme objects are not serializable.
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
