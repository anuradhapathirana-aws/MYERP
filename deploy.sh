#!/usr/bin/env bash
# Deployment script — run on the server after pulling the latest code.
# Usage: bash deploy.sh
set -euo pipefail

cd "$(dirname "$0")/backend"

echo "==> Installing/updating Composer dependencies..."
composer install --no-dev --optimize-autoloader

echo "==> Caching config, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Running database migrations..."
php artisan migrate --force

echo "==> Seeding global settings (module toggles)..."
php artisan db:seed --class=GlobalSettingSeeder --force

echo "==> Restarting queue workers..."
php artisan queue:restart

echo "==> Deployment complete."
