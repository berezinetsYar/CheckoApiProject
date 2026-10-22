FROM php:8.2-cli-alpine

# Расширения PDO / pdo_pgsql / curl
RUN apk add --no-cache postgresql-dev libzip oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install pdo pdo_pgsql curl opcache \
    && apk del .build-deps

WORKDIR /var/www/html

COPY . /var/www/html

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
