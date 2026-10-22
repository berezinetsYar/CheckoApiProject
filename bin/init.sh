#!/usr/bin/env bash
#
# Скрипт инициализации проекта: установка зависимостей и генерация автозагрузчика.
# Использование: ./bin/init.sh   (или: bash bin/init.sh)
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Определяем, как запускать composer (локально или через docker)
if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
elif command -v docker >/dev/null 2>&1 && [ -f docker-compose.yml ]; then
    COMPOSER="docker compose run --rm php composer"
else
    echo "Ошибка: Composer не найден. Установите Composer или Docker." >&2
    exit 1
fi

echo "==> Установка зависимостей..."
$COMPOSER install

echo "==> Генерация оптимизированного автозагрузчика (PSR-4)..."
$COMPOSER dump-autoload --optimize

echo "==> Готово. Проверка:"
php -r "require 'vendor/autoload.php'; echo 'Autoload OK', PHP_EOL;" || \
    $COMPOSER exec -- php -r "require 'vendor/autoload.php'; echo 'Autoload OK', PHP_EOL;"
