#!/usr/bin/env bash
#
# TATA SALES — Deploy script (VPS / Laravel Cloud)
# Jalankan dari: /var/www/tata-sales  (atau ubah APP_PATH di bawah)
#
# Prasyarat di server: git, php>=8.3 (pgsql, mbstring, intl, curl, bcmath,
# fileinfo, openssl), composer, node>=20 + npm, supervisor, nginx.
#
# Penggunaan:
#   sudo ./deploy/deploy.sh deploy     # git pull + build + optimize
#   sudo ./deploy/deploy.sh fresh      # deploy + php artisan migrate --force
#
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/tata-sales}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
RUN_USER="${RUN_USER:-www-data}"

cd "$APP_PATH"

echo "==> 1/8 Git pull"
git pull --ff-only

echo "==> 2/8 Composer install (no-dev)"
sudo -u "$RUN_USER" "$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> 3/8 NPM build"
sudo -u "$RUN_USER" npm ci --no-audit --no-fund
sudo -u "$RUN_USER" npm run build

echo "==> 4/8 Storage links & permissions"
sudo -u "$RUN_USER" "$PHP_BIN" artisan storage:link 2>/dev/null || true
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p storage/logs
chown -R "$RUN_USER":"$RUN_USER" storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> 5/8 Artisan optimize"
sudo -u "$RUN_USER" "$PHP_BIN" artisan config:cache
sudo -u "$RUN_USER" "$PHP_BIN" artisan route:cache
sudo -u "$RUN_USER" "$PHP_BIN" artisan view:cache
sudo -u "$RUN_USER" "$PHP_BIN" artisan event:cache

if [[ "${1:-deploy}" == "fresh" ]]; then
    echo "==> 6/8 Database migrate --force"
    sudo -u "$RUN_USER" "$PHP_BIN" artisan migrate --force
    echo "==> 7/8 Fresh: seed hanya jika dibutuhkan"
    if [[ "${SEED:-false}" == "true" ]]; then
        sudo -u "$RUN_USER" "$PHP_BIN" artisan db:seed --force
    fi
else
    echo "==> 6-7/8 Skip migrate (gunakan: deploy.sh fresh untuk migrate)"
fi

echo "==> 8/8 Restart worker (supervisor) & reload nginx"
supervisorctl restart tata-sales-worker || true
nginx -s reload || true

echo "==> Selesai. Cek: curl -sI https://<domain>"