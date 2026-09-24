#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
    echo "Missing .env. Create it from .env.production.example before deployment."
    exit 1
fi

# Hostinger disables proc_open, so Composer cannot launch post-install scripts.
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
rm -f bootstrap/cache/*.php
php artisan package:discover --ansi

mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    public/uploads

chmod -R ug+rwX storage bootstrap/cache public/uploads

if grep -Eq '^APP_KEY=$' .env; then
    php artisan key:generate --force
fi

php artisan optimize:clear
php artisan migrate --force

if [[ ! -e public/storage ]]; then
    # Laravel's storage:link calls PHP exec(), which Hostinger disables.
    ln -s "$(pwd)/storage/app/public" public/storage
fi

php artisan optimize
php artisan platform:readiness --strict

echo "Hostinger application deployment checks passed."
