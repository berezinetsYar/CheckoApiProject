FROM php:8.2-cli-alpine

# Расширения: pdo, pdo_pgsql, curl (+ opcache для производительности)
RUN docker-php-ext-install pdo pdo_pgsql curl opcache

WORKDIR /var/www/html

# Встроенный веб-сервер PHP: public/index.php — front controller
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
