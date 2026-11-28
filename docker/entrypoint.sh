#!/usr/bin/env sh
set -eu

PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:clear
php artisan view:clear

# Garantiza que /public/storage apunte al disco público de Laravel.
# En contenedores nuevos el enlace puede no existir todavía.
php artisan storage:link || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

# Activación específica de Sensaciones: solo estas migraciones aditivas.
# No cambiar RUN_MIGRATIONS ni ejecutar seeders para habilitar el módulo.
if [ "${SENSACIONES_PREPARE_SCHEMA:-false}" = "true" ]; then
  if php artisan migrate --force \
    --path=database/migrations/2026_11_27_230000_create_sensaciones_media_table.php \
    --path=database/migrations/2026_11_27_230001_create_sensaciones_collections.php \
    --path=database/migrations/2026_11_27_230002_create_sensaciones_settings.php \
    --path=database/migrations/2026_11_27_230003_create_sensaciones_import_items.php \
    --path=database/migrations/2026_11_28_000000_add_sensaciones_video_posters.php; then
    echo "Sensaciones: migraciones específicas completadas."
  else
    echo "Sensaciones: migraciones fallidas; se desactiva el módulo para preservar el servicio existente." >&2
    export SENSACIONES_ENABLED=false
  fi
fi

if [ "${RUN_SEEDER:-false}" = "true" ]; then
  php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
