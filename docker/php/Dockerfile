FROM composer:2 AS composer

FROM php:8.3-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates supervisor nginx-light \
    libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libxml2-dev libxslt1-dev libtidy-dev libonig-dev libmagickwand-dev \
    ffmpeg imagemagick clamav netcat-openbsd \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl opcache pcntl pdo_pgsql pgsql sockets tidy xsl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN groupadd -g 1000 appgroup \
    && useradd -u 1000 -g appgroup -m -s /bin/bash appuser \
    && mkdir -p /var/www/html /var/log/supervisor /var/run/php \
    && chown -R appuser:appgroup /var/www/html

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader --no-scripts

COPY . .

RUN mkdir -p storage/app/temp storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R appuser:appgroup /var/www/html \
    && chmod +x docker/scripts/healthcheck.sh

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 9000

CMD ["php-fpm", "--nodaemonize"]
