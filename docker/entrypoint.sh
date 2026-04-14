#!/usr/bin/env bash

set -euo pipefail

if [[ -n "${DB_HOST:-}" ]]; then
    mysql_args=(
        -h"${DB_HOST}"
        -P"${DB_PORT:-3306}"
        -u"${DB_USERNAME:-root}"
    )

    if [[ -n "${DB_PASSWORD:-}" ]]; then
        mysql_args+=(-p"${DB_PASSWORD}")
    fi

    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
    until mysqladmin ping "${mysql_args[@]}" --silent; do
        sleep 2
    done
fi

mkdir -p \
    bootstrap/cache \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

chown -R www-data:www-data bootstrap/cache storage

if [[ ! -L public/storage ]]; then
    php artisan storage:link >/dev/null 2>&1 || true
fi

if [[ -z "${APP_KEY:-}" ]]; then
    echo "APP_KEY is empty. Generate one with 'php artisan key:generate --show' and set it in .env.docker."
fi

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
