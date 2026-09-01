#!/usr/bin/env bash
# Обёртка над docker compose: проект целиком живёт в контейнерах,
# на хосте нужен только docker.
set -euo pipefail

cd "$(dirname "$0")/.."

export DOCKER_UID="$(id -u)"
export DOCKER_GID="$(id -g)"

compose() { docker compose "$@"; }

case "${1:-up}" in
    install)
        # Первый запуск на чистой машине: зависимости и сборка фронтенда.
        [ -f .env ] || cp .env.example .env
        compose run --rm --no-deps app composer install
        compose run --rm --no-deps -w /var/www/html vite npm ci --no-audit --no-fund
        compose run --rm --no-deps -w /var/www/html vite npm run build
        compose run --rm --no-deps app php artisan migrate --seed --force
        echo "Готово. Запуск: scripts/dev.sh up"
        ;;
    up)
        [ -f .env ] || cp .env.example .env
        [ -d vendor ] || compose run --rm --no-deps app composer install
        [ -f public/build/manifest.json ] || compose run --rm --no-deps -w /var/www/html vite sh -c "npm ci --no-audit --no-fund && npm run build"
        # Остался от прошлого запуска vite — иначе приложение будет искать
        # несуществующий дев-сервер и отдавать пустую страницу.
        rm -f public/hot
        compose up -d --build app web queue
        echo "DealWatch: http://127.0.0.1:${DEALWATCH_PORT:-8000}/deals"
        echo "  вход: dealer@dealwatch.test / password"
        echo "  сбор каждые 3 минуты: scripts/dev.sh scheduler"
        ;;
    dev)
        # То же плюс vite с горячей перезагрузкой фронтенда.
        [ -f .env ] || cp .env.example .env
        [ -d vendor ] || compose run --rm --no-deps app composer install
        compose up -d --build app web queue vite
        echo "DealWatch: http://127.0.0.1:${DEALWATCH_PORT:-8000}/deals (vite на 5188)"
        ;;
    scheduler)
        compose --profile scheduler up -d scheduler
        ;;
    down)
        compose --profile scheduler --profile dev down
        rm -f public/hot
        ;;
    logs)
        compose logs -f "${2:-queue}"
        ;;
    artisan)
        shift
        compose run --rm --no-deps app php artisan "$@"
        ;;
    seed)
        compose run --rm --no-deps app php artisan migrate --seed --force
        ;;
    test)
        shift
        compose run --rm --no-deps app php vendor/bin/phpunit "$@"
        ;;
    shell)
        compose exec app sh
        ;;
    *)
        echo "scripts/dev.sh [install|up|dev|scheduler|down|logs [сервис]|artisan …|seed|test|shell]"
        exit 1
        ;;
esac
