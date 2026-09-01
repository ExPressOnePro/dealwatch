#!/bin/sh
# Общая подготовка для всех контейнеров приложения: веба, очереди и планировщика.
set -e

cd /var/www/html

# SQLite по умолчанию: файл базы должен существовать до миграций.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    [ -f "$DB_FILE" ] || (mkdir -p "$(dirname "$DB_FILE")" && touch "$DB_FILE")
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Ключ приложения шифрует сессии и секреты в админских настройках (в том числе
# ключ OpenAI). В проде он обязан приезжать из .env: сгенерированный внутри
# контейнера потеряется при пересоздании, и сохранённые секреты станет нечем
# расшифровать.
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
    if [ "${APP_ENV:-production}" = "production" ]; then
        echo "APP_KEY не задан. Сгенерируй его один раз и положи в .env:" >&2
        echo "  docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show" >&2
        exit 1
    fi

    php artisan key:generate --force --no-interaction || true
fi

# Миграции гоняет только веб-контейнер, чтобы очередь и планировщик
# не пытались сделать это одновременно.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# Контейнер стартует от root, а php-fpm обслуживает запросы под www-data:
# без этого SQLite и кеш сессий оказываются недоступны на запись.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
fi

# Кеши конфига и роутов нужны в проде и мешают в разработке.
if [ "${DEALWATCH_OPTIMIZE:-true}" = "true" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
else
    php artisan config:clear --no-interaction >/dev/null 2>&1 || true
    php artisan route:clear --no-interaction >/dev/null 2>&1 || true
fi

exec "$@"
