<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'translink_gps');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PERSISTENT', false);
define('DB_RETRY_ATTEMPTS', 1);
define('DB_RETRY_DELAY_MS', 100);

define('UPLOAD_PATH', __DIR__ . '/uploads');
define('SITE_NAME', 'Translink GPS Library');
define('SITE_URL', 'http://localhost:8000');
define('SEARCH_PAGE_SIZE', 24);
define('ADMIN_TABLE_PAGE_SIZE', 200);
define('DEBUG', false);

define('MAX_FILE_SIZE', 104857600);

define('ALLOWED_CONFIG_EXT', ['cfg', 'txt', 'conf', 'ini', 'csv', 'xls', 'xlsx']);
define('ALLOWED_FIRMWARE_EXT', ['fw', 'bin', 'hex', 'dfu', 'xim', 'cfw']);
define('ALLOWED_MANUAL_EXT', ['pdf', 'doc', 'docx', 'txt']);
define('ALLOWED_SOFTWARE_EXT', ['exe', 'msi', 'zip', 'rar', '7z', 'gz', 'xim', 'cif']);
