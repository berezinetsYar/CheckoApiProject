FROM php:8.2-cli-alpine

# Устанавливаем зависимости и расширения
RUN apk add --no-cache \
    icu-dev \
    postgresql-dev \
    libxml2-dev \
    curl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl opcache

# Устанавливаем Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY . /app

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]