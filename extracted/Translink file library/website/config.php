<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$driver = getenv('DB_DRIVER') ?: 'mysql';
define('DB_DRIVER', $driver);

$dbUrl = getenv('DATABASE_URL');
if ($dbUrl) {
    $parts = parse_url($dbUrl);
    define('DB_HOST', $parts['host'] ?? '127.0.0.1');
    define('DB_PORT', $parts['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
    define('DB_NAME', ltrim($parts['path'] ?? 'translink_gps', '/'));
    define('DB_USER', $parts['user'] ?? 'root');
    define('DB_PASS', $parts['pass'] ?? '');
} else {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'));
    define('DB_NAME', getenv('DB_NAME') ?: 'translink_gps');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
}

define('DB_PERSISTENT', filter_var(getenv('DB_PERSISTENT') ?: false, FILTER_VALIDATE_BOOLEAN));
define('DB_RETRY_ATTEMPTS', (int)(getenv('DB_RETRY_ATTEMPTS') ?: 30));
define('DB_RETRY_DELAY_MS', (int)(getenv('DB_RETRY_DELAY_MS') ?: 100));
define('DB_MAINTENANCE_DB', getenv('DB_MAINTENANCE_DB') ?: ($driver === 'pgsql' ? 'postgres' : 'mysql'));

define('UPLOAD_PATH', __DIR__ . '/uploads');
define('SITE_NAME', getenv('SITE_NAME') ?: 'Translink GPS Library');
define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost:8000');
define('SEARCH_PAGE_SIZE', (int)(getenv('SEARCH_PAGE_SIZE') ?: 24));
define('ADMIN_TABLE_PAGE_SIZE', (int)(getenv('ADMIN_TABLE_PAGE_SIZE') ?: 200));
define('DEBUG', filter_var(getenv('DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

define('MAX_FILE_SIZE', 104857600);

define('ALLOWED_CONFIG_EXT', ['cfg', 'txt', 'conf', 'ini', 'csv', 'xls', 'xlsx']);
define('ALLOWED_FIRMWARE_EXT', ['fw', 'bin', 'hex', 'dfu', 'xim', 'cfw']);
define('ALLOWED_MANUAL_EXT', ['pdf', 'doc', 'docx', 'txt']);
define('ALLOWED_SOFTWARE_EXT', ['exe', 'msi', 'zip', 'rar', '7z', 'gz', 'xim', 'cif']);
