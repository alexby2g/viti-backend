#!/usr/bin/env sh
set -eu

PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:clear
php artisan view:clear

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

if [ "${RUN_SEEDER:-false}" = "true" ]; then
  php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
