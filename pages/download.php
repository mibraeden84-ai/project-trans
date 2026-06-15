<?php
if (!isLoggedIn()) {
    $redirect = 'index.php?page=download&type=' . urlencode($_GET['type'] ?? '') . '&id=' . ((int)($_GET['id'] ?? 0));
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);

$tableMap = [
    'config' => 'config_files',
    'firmware' => 'firmware_files',
    'manual' => 'manuals',
    'software' => 'software_files',
];

if (!$id || !isset($tableMap[$type])) {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = '404';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="error-page"><h1>404</h1><p>File not found</p><a href="index.php" class="btn">Back to Home</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$table = $tableMap[$type];
$file = $db->fetchOne("SELECT * FROM $table WHERE id = ? AND status = 'active'", [$id]);

if (!$file) {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = '404';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="error-page"><h1>404</h1><p>File not found</p><a href="index.php" class="btn">Back to Home</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$filePath = __DIR__ . '/../' . $file['file_path'];

if (!file_exists($filePath)) {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = '404';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="error-page"><h1>404</h1><p>File does not exist on server</p><a href="index.php" class="btn">Back to Home</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// Increment download count
$db->incrementDownload($table, $id);
incrementUserDownloadCount((int)($_SESSION['user_id'] ?? 0), 1);
logUserDownloadEvent((int)($_SESSION['user_id'] ?? 0), $type, $id, (string)($file['name'] ?? ''));

// Serve file
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mimeTypes = [
    'cfg' => 'text/plain',
    'txt' => 'text/plain',
    'conf' => 'text/plain',
    'ini' => 'text/plain',
    'fw' => 'application/octet-stream',
    'bin' => 'application/octet-stream',
    'hex' => 'application/octet-stream',
    'pdf' => 'application/pdf',
    'exe' => 'application/octet-stream',
    'msi' => 'application/x-msi',
    'zip' => 'application/zip',
    'rar' => 'application/vnd.rar',
    '7z'  => 'application/x-7z-compressed',
    'gz'  => 'application/gzip',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

$encodedName = rawurlencode($file['name']);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . $encodedName);
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

readfile($filePath);
exit;
