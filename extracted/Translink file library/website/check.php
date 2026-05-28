<?php
// Quick health check for Translink File Library

$checks = [];
$allPass = true;

// PHP version
$checks[] = [
    'name' => 'PHP Version',
    'pass' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'msg'  => PHP_VERSION,
];

require_once __DIR__ . '/config.php';

// Required extensions
$exts = ['pdo', DB_DRIVER === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql'];
foreach ($exts as $ext) {
    $checks[] = [
        'name' => "Extension: $ext",
        'pass' => extension_loaded($ext),
        'msg'  => extension_loaded($ext) ? 'loaded' : 'MISSING',
    ];
}

// Config file
$checks[] = [
    'name' => 'Config file',
    'pass' => file_exists(__DIR__ . '/config.php'),
    'msg'  => 'config.php ' . (file_exists(__DIR__ . '/config.php') ? 'found' : 'MISSING'),
];

// Uploads directory
$uploadDirs = ['configs', 'firmware', 'manuals'];
foreach ($uploadDirs as $dir) {
    $path = __DIR__ . '/uploads/' . $dir;
    $checks[] = [
        'name' => "Uploads: $dir/",
        'pass' => is_dir($path),
        'msg'  => is_dir($path) ? 'exists' : 'MISSING',
    ];
}

// Database connection
try {
    define('DB_THROW_EXCEPTIONS', true);
    require_once __DIR__ . '/includes/database.php';
    $db = Database::getInstance();
    $db->query('SELECT 1');
    $brandCount = $db->fetchOne("SELECT COUNT(*) as c FROM brands")['c'];
    $checks[] = [
        'name' => 'Database connection',
        'pass' => true,
        'msg'  => "Connected — $brandCount brands found",
    ];
} catch (Exception $e) {
    $checks[] = [
        'name' => 'Database connection',
        'pass' => false,
        'msg'  => 'FAILED: ' . $e->getMessage(),
    ];
}

// Output
$total = count($checks);
$passed = count(array_filter($checks, fn($c) => $c['pass']));
$failed = $total - $passed;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Check — Translink File Library</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; padding: 40px; color: #333; }
        .container { max-width: 640px; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .subtitle { color: #6c757d; margin-bottom: 24px; font-size: 0.9rem; }
        .summary { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: center; }
        .summary .big { font-size: 3rem; font-weight: 700; }
        .summary .big.pass { color: #00a86b; }
        .summary .big.fail { color: #e74c3c; }
        .summary .label { font-size: 0.9rem; color: #6c757d; }
        .check-list { list-style: none; }
        .check-item { background: #fff; border-radius: 8px; padding: 14px 20px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .check-item .name { font-size: 0.9rem; }
        .check-item .status { font-size: 0.8rem; font-weight: 600; padding: 4px 12px; border-radius: 12px; }
        .status.pass { background: #d4edda; color: #155724; }
        .status.fail { background: #f8d7da; color: #721c24; }
        .actions { margin-top: 24px; display: flex; gap: 12px; }
        .btn { display: inline-block; padding: 10px 20px; background: #005aa0; color: #fff; text-decoration: none; border-radius: 8px; font-size: 0.9rem; font-weight: 600; }
        .btn:hover { background: #003d73; }
        .btn-outline { background: transparent; color: #005aa0; border: 2px solid #005aa0; }
        .btn-outline:hover { background: #e8f0fe; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📡 System Check</h1>
        <p class="subtitle">Translink File Library — Health Report</p>

        <div class="summary">
            <div class="big <?= $failed === 0 ? 'pass' : 'fail' ?>"><?= $passed ?>/<?= $total ?></div>
            <div class="label">checks passed</div>
        </div>

        <ul class="check-list">
            <?php foreach ($checks as $c): ?>
            <li class="check-item">
                <span class="name"><?= htmlspecialchars($c['name']) ?></span>
                <span class="status <?= $c['pass'] ? 'pass' : 'fail' ?>">
                    <?= htmlspecialchars($c['msg']) ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="actions">
            <a href="index.php" class="btn"><i class="fas fa-arrow-left"></i> Go to Library</a>
            <a href="check.php" class="btn btn-outline">🔄 Re-check</a>
        </div>
    </div>
</body>
</html>
