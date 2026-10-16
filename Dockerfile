# Shared runtime: PHP extensions and Phalcon, compiled once for every target.
FROM php:8.3-fpm-bookworm AS base
ARG PHALCON_VERSION=5.22.1
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl ca-certificates unzip libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libpcre2-dev libsqlite3-dev libssl-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite mbstring gd intl \
    && curl -fsSL "https://github.com/phalcon/cphalcon/releases/download/v${PHALCON_VERSION}/phalcon-pecl.tgz" -o /tmp/phalcon.tgz \
    && pecl install /tmp/phalcon.tgz \
    && docker-php-ext-enable phalcon \
    && rm -rf /var/lib/apt/lists/* /tmp/phalcon.tgz
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/cantera.ini
WORKDIR /var/www/html

# Test image: development dependencies (PHPUnit). Used by `docker compose run --rm test`.
FROM base AS test
ENV APP_ENV=testing DB_DRIVER=sqlite
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload \
    && mkdir -p var/cache var/session var/log public/uploads/players
CMD ["composer", "test"]

# Production image (default target).
FROM base AS app
COPY . .
RUN composer install --no-dev --no-interaction --optimize-autoloader \
    && mkdir -p var/cache var/session var/log public/uploads/players \
    && chown -R www-data:www-data var public/uploads
COPY docker/entrypoint.sh /usr/local/bin/cantera-entrypoint
RUN chmod +x /usr/local/bin/cantera-entrypoint
ENTRYPOINT ["cantera-entrypoint"]
CMD ["php-fpm"]
