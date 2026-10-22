#!/usr/bin/env bash
#
# Инициализация проекта: установка зависимостей и оптимизированной автозагрузки.
#
set -euo pipefail

cd "$(dirname "$0")/.."

if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
elif [ -f docker-compose.yml ] && command -v docker >/dev/null 2>&1; then
    COMPOSER="docker compose run --rm php composer"
else
    echo "Не найден ни composer, ни docker. Установите один из них." >&2
    exit 1
fi

echo "=> composer install"
$COMPOSER install

echo "=> composer dump-autoload --optimize"
$COMPOSER dump-autoload --optimize

echo "=> Проверка автозагрузки"
if command -v php >/dev/null 2>&1; then
    php -r 'require "vendor/autoload.php"; echo "Autoload OK\n";'
else
    docker compose run --rm php php -r 'require "vendor/autoload.php"; echo "Autoload OK\n";'
fi

echo "Готово. Запуск: docker compose up -d --build; проверка: curl http://localhost:8080/api/company/7707083893"
