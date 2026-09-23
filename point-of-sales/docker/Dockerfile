# Production image for point-of-sales (FrankenPHP, single container serves HTTP on :80)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM dunglas/frankenphp:1-php8.4-alpine
RUN install-php-extensions pdo_mysql bcmath intl gd zip exif opcache pcntl
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-production.ini $PHP_INI_DIR/conf.d/zz-production.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV SERVER_NAME=:80 \
    APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/pos-entrypoint
ENTRYPOINT ["pos-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
