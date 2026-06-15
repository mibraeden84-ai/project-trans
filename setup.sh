#!/bin/bash
# Translink GPS Library - PostgreSQL Setup (Linux/Mac)
# Run: chmod +x setup.sh && ./setup.sh

echo "========================================"
echo "  Translink GPS Library - Setup"
echo "========================================"

if ! command -v php >/dev/null 2>&1; then
    echo "[ERROR] PHP not found."
    exit 1
fi

if ! php -m | grep -qi "pdo_pgsql"; then
    echo "[ERROR] PHP extension pdo_pgsql is not enabled."
    echo "  Install/enable the PostgreSQL PDO extension for your PHP version."
    exit 1
fi

echo "[1/3] Installing PostgreSQL database..."
php install.php
if [ $? -ne 0 ]; then
    echo "[ERROR] Installation failed."
    echo "  - Is PostgreSQL running? (for example: sudo systemctl start postgresql)"
    echo "  - Are DB_USER and DB_PASS correct in config.php or environment variables?"
    exit 1
fi

echo "[2/3] Starting server..."
echo "  Site:  http://localhost:8000"
echo "  Admin: http://localhost:8000/admin/login.php"
echo "  Login: admin / password"
echo ""
php -S 0.0.0.0:8000 -t "$(dirname "$0")"
