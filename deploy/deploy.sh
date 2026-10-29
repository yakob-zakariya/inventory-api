#!/usr/bin/env bash
# Update the API on the server to the latest main. Run as the deploy user:
#   /var/www/inventory-api/deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "Backing up the database before changing anything..."
php artisan backup:run --only-db --disable-notifications

php artisan down --retry=15
trap 'php artisan up' EXIT

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

php artisan migrate --force
# Adds new permissions and gives them to the owner; roles the shop edited are kept.
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan permission:cache-reset

php artisan storage:link 2>/dev/null || true
php artisan optimize

sudo systemctl reload php8.5-fpm

php artisan up
trap - EXIT

echo "Checking the data against the ledgers..."
php artisan inventory:verify
