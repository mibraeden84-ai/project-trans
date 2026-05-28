<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/database.php';

$db = Database::getInstance();
$files = $argc > 1 ? array_slice($argv, 1) : [__DIR__ . '/api-schema.sql'];
$count = 0;
$errors = 0;
foreach ($files as $file) {
    $path = __DIR__ . '/' . ltrim($file, '/');
    if (!file_exists($path)) {
        echo "[ERROR] File not found: $path\n";
        $errors++;
        continue;
    }
    echo "[INFO] Running migration: $file\n";
    $sql = file_get_contents($path);
$statements = explode(';', $sql);

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (empty($stmt) || str_starts_with($stmt, '--')) continue;
    try {
        $db->execute($stmt);
        $count++;
    } catch (Exception $e) {
        echo "[WARN] " . $e->getMessage() . "\n";
        $errors++;
    }
}

}
echo "Migration complete: $count statements executed, $errors warnings\n";
