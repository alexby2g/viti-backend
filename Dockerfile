FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libonig-dev libpq-dev postgresql-client unzip \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring intl zip opcache \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/viti-entrypoint

RUN chmod +x /usr/local/bin/viti-entrypoint \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000
ENTRYPOINT ["viti-entrypoint"]
CMD ["apache2-foreground"]
