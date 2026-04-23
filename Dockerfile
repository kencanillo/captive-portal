FROM composer:2.8 AS composer_deps

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
	--no-scripts \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

FROM node:20-bookworm-slim AS frontend_deps

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY --from=composer_deps /app/vendor ./vendor
COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
RUN npm run build

FROM php:8.4-apache-bookworm AS app

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        ca-certificates \
        curl \
        default-mysql-client \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_mysql zip \
    && a2enmod headers rewrite mpm_prefork \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .
COPY --from=composer_deps /app/vendor ./vendor
COPY --from=frontend_deps /app/public/build ./public/build
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-captive-portal.ini
COPY docker/mpm-prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/entrypoint.sh /usr/local/bin/portal-entrypoint

RUN chmod +x /usr/local/bin/portal-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && rm -f bootstrap/cache/*.php \
    && chown -R www-data:www-data bootstrap/cache storage

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["portal-entrypoint"]
CMD ["apache2-foreground"]
