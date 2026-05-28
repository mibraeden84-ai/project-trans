#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/html"
PORT="${PORT:-10000}"
UPLOADS_DIR="${APP_ROOT}/uploads"
SEED_UPLOADS_DIR="/opt/translink-seed/uploads"
SEED_MARKER="${UPLOADS_DIR}/.render-seeded"
SESSION_DIR="${APP_ROOT}/tmp/sessions"

mkdir -p \
  "${UPLOADS_DIR}" \
  "${UPLOADS_DIR}/brands" \
  "${UPLOADS_DIR}/configs" \
  "${UPLOADS_DIR}/firmware" \
  "${UPLOADS_DIR}/manuals" \
  "${UPLOADS_DIR}/models" \
  "${UPLOADS_DIR}/software" \
  "${UPLOADS_DIR}/users" \
  "${SESSION_DIR}"

if [ -d "${SEED_UPLOADS_DIR}" ] && [ ! -f "${SEED_MARKER}" ]; then
  cp -an "${SEED_UPLOADS_DIR}/." "${UPLOADS_DIR}/"
  touch "${SEED_MARKER}"
fi

chown -R www-data:www-data "${UPLOADS_DIR}" "${SESSION_DIR}"

php "${APP_ROOT}/render-init.php"

sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
