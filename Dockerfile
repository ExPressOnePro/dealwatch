# syntax=docker/dockerfile:1

# ---------- PHP-зависимости ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------- Сборка фронтенда ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ---------- Базовый рантайм ----------
FROM php:8.4-fpm-alpine AS runtime

# pdo_sqlite уже в образе; pdo_pgsql — на случай переезда с SQLite на Postgres,
# pcntl нужен queue:work для корректной обработки сигналов остановки.
RUN apk add --no-cache postgresql-dev libzip-dev icu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip pcntl opcache \
    && apk del postgresql-dev libzip-dev icu-dev \
    && apk add --no-cache libpq libzip icu-libs

COPY docker/php/php.ini /usr/local/etc/php/conf.d/dealwatch.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------- Образ для продакшена: код внутри ----------
FROM runtime AS app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
# Кеш пакетов пересобираем в образе: с хоста он приезжает с dev-провайдерами,
# которых в --no-dev сборке нет.
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache database database

# ---------- Веб-сервер со статикой внутри ----------
# Статика лежит в самом образе nginx: при обновлении версии приложения
# ассеты обновляются вместе с ним, без ручной синхронизации томов.
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

# ---------- Образ для разработки: код монтируется томом ----------
FROM runtime AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer
