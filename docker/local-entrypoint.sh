#!/bin/sh
set -eu
[ -f .env ] || cp .env.local.example .env
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan config:clear
if ! grep -q '^APP_KEY=base64:' .env; then php artisan key:generate --force; fi
php artisan migrate --force
# Solo datos base de plataforma (planes/cuestionario/configuración). Nunca borra
# el trabajo de prueba al reiniciar el contenedor.
php artisan db:seed --force
if [ ! -L public/storage ]; then php artisan storage:link; fi
exec php artisan serve --host=0.0.0.0 --port=8000
