FROM php:8.2-cli-alpine

# Расширения: pdo, pdo_pgsql, curl (+ opcache)
RUN apk add --no-cache icu-dev postgresql-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_pgsql curl opcache

WORKDIR /app
COPY . /app

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
