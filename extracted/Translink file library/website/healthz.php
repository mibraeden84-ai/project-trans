<?php
require_once __DIR__ . '/config.php';

$checks = [];
$healthy = true;

$requiredDirs = [
    'uploads' => UPLOAD_PATH,
    'configs' => UPLOAD_PATH . '/configs',
    'firmware' => UPLOAD_PATH . '/firmware',
    'manuals' => UPLOAD_PATH . '/manuals',
    'software' => UPLOAD_PATH . '/software',
    'sessions' => __DIR__ . '/tmp/sessions',
];

foreach ($requiredDirs as $name => $path) {
    $ok = is_dir($path) && is_readable($path);
    $checks[$name] = [
        'ok' => $ok,
        'path' => $path,
    ];
    if (!$ok) {
        $healthy = false;
    }
}

try {
    require_once __DIR__ . '/includes/database.php';
    $db = Database::getInstance();
    $db->query('SELECT 1');
    $checks['database'] = ['ok' => true];
} catch (Throwable $e) {
    $healthy = false;
    $checks['database'] = [
        'ok' => false,
        'error' => $e->getMessage(),
    ];
}

http_response_code($healthy ? 200 : 503);
header('Content-Type: application/json');

echo json_encode([
    'ok' => $healthy,
    'service' => 'translink-file-library',
    'timestamp' => gmdate('c'),
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
