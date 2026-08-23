FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql opcache pcntl \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
