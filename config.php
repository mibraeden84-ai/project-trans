<?php
date_default_timezone_set('Africa/Addis_Ababa');
define('DASH_TIMEZONE', 'Africa/Addis_Ababa');
define('DASH_TIMEZONE_LABEL', 'EAT');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/hosting-config.php')) {
    require_once __DIR__ . '/hosting-config.php';
}

function define_if_missing(string $name, mixed $value): void {
    if (!defined($name)) {
        define($name, $value);
    }
}

$driver = getenv('DB_DRIVER') ?: 'mysql';
define_if_missing('DB_DRIVER', $driver);

$dbUrl = getenv('DATABASE_URL');
if ($dbUrl) {
    $parts = parse_url($dbUrl);
    define_if_missing('DB_HOST', $parts['host'] ?? '127.0.0.1');
    define_if_missing('DB_PORT', $parts['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
    define_if_missing('DB_NAME', ltrim($parts['path'] ?? 'translink_gps', '/'));
    define_if_missing('DB_USER', $parts['user'] ?? 'root');
    define_if_missing('DB_PASS', $parts['pass'] ?? '');
} else {
    define_if_missing('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define_if_missing('DB_PORT', getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'));
    define_if_missing('DB_NAME', getenv('DB_NAME') ?: 'translink_gps');
    define_if_missing('DB_USER', getenv('DB_USER') ?: 'root');
    define_if_missing('DB_PASS', getenv('DB_PASS') ?: '');
}

define_if_missing('DB_PERSISTENT', filter_var(getenv('DB_PERSISTENT') ?: false, FILTER_VALIDATE_BOOLEAN));
define_if_missing('DB_RETRY_ATTEMPTS', (int)(getenv('DB_RETRY_ATTEMPTS') ?: 30));
define_if_missing('DB_RETRY_DELAY_MS', (int)(getenv('DB_RETRY_DELAY_MS') ?: 100));
define_if_missing('DB_MAINTENANCE_DB', getenv('DB_MAINTENANCE_DB') ?: ($driver === 'pgsql' ? 'postgres' : 'mysql'));

define_if_missing('UPLOAD_PATH', __DIR__ . '/uploads');
define_if_missing('SITE_NAME', getenv('SITE_NAME') ?: 'Translink GPS Library');
define_if_missing('SITE_URL', getenv('SITE_URL') ?: 'http://localhost:8000');
define_if_missing('SEARCH_PAGE_SIZE', (int)(getenv('SEARCH_PAGE_SIZE') ?: 24));
define_if_missing('ADMIN_TABLE_PAGE_SIZE', (int)(getenv('ADMIN_TABLE_PAGE_SIZE') ?: 200));
define_if_missing('DEBUG', filter_var(getenv('DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

define_if_missing('MAX_FILE_SIZE', 104857600);

define_if_missing('ALLOWED_CONFIG_EXT', ['cfg', 'txt', 'conf', 'ini', 'csv', 'xls', 'xlsx']);
define_if_missing('ALLOWED_FIRMWARE_EXT', ['fw', 'bin', 'hex', 'dfu', 'xim', 'cfw']);
define_if_missing('ALLOWED_MANUAL_EXT', ['pdf', 'doc', 'docx', 'txt']);
define_if_missing('ALLOWED_SOFTWARE_EXT', ['exe', 'msi', 'zip', 'rar', '7z', 'gz', 'xim', 'cif']);
