<?php
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$dsn = DB_DRIVER === 'pgsql'
    ? "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=prefer"
    : "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

if (defined('DB_PERSISTENT') && DB_PERSISTENT) {
    $options[PDO::ATTR_PERSISTENT] = true;
}
if (DB_DRIVER !== 'pgsql') {
    $options[PDO::ATTR_TIMEOUT] = defined('DB_CONNECT_TIMEOUT') ? DB_CONNECT_TIMEOUT : 5;
}

$attempts = max(5, defined('DB_RETRY_ATTEMPTS') ? DB_RETRY_ATTEMPTS * 10 : 30);
$delaySeconds = 2;
$pdo = null;
$lastError = null;

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        break;
    } catch (Throwable $e) {
        $lastError = $e;
        fwrite(STDOUT, "[render-init] Waiting for database ({$attempt}/{$attempts})...\n");
        sleep($delaySeconds);
    }
}

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "[render-init] Database connection failed: " . ($lastError ? $lastError->getMessage() : 'unknown error') . "\n");
    exit(1);
}

function renderTableExists(PDO $pdo, string $table): bool {
    if (DB_DRIVER === 'pgsql') {
        $stmt = $pdo->prepare("SELECT to_regclass(?) IS NOT NULL");
        $stmt->execute(['public.' . $table]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function renderEnsureBootstrapMarker(PDO $pdo): void {
    if (DB_DRIVER === 'pgsql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_bootstrap_state (
                id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                schema_version VARCHAR(64) NOT NULL DEFAULT 'schema.sql',
                bootstrapped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $pdo->exec(
            "INSERT INTO app_bootstrap_state (id, schema_version)
             VALUES (1, 'schema.sql')
             ON CONFLICT (id) DO UPDATE SET schema_version = EXCLUDED.schema_version"
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_bootstrap_state (
            id TINYINT PRIMARY KEY DEFAULT 1,
            schema_version VARCHAR(64) NOT NULL DEFAULT 'schema.sql',
            bootstrapped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        "INSERT INTO app_bootstrap_state (id, schema_version)
         VALUES (1, 'schema.sql')
         ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version)"
    );
}

$markerExists = renderTableExists($pdo, 'app_bootstrap_state');
if ($markerExists) {
    fwrite(STDOUT, "[render-init] Bootstrap marker found. Skipping schema load.\n");
    exit(0);
}

$coreTables = ['users', 'brands', 'device_models', 'config_files', 'firmware_files', 'manuals', 'software_files'];
$coreTableCount = 0;
foreach ($coreTables as $tableName) {
    if (renderTableExists($pdo, $tableName)) {
        $coreTableCount++;
    }
}

if ($coreTableCount === count($coreTables)) {
    renderEnsureBootstrapMarker($pdo);
    fwrite(STDOUT, "[render-init] Existing database detected. Marker created without re-seeding.\n");
    exit(0);
}

$schemaPath = __DIR__ . '/schema.sql';
$schemaSql = @file_get_contents($schemaPath);
if ($schemaSql === false) {
    fwrite(STDERR, "[render-init] Unable to read schema.sql\n");
    exit(1);
}

try {
    $pdo->exec($schemaSql);
    renderEnsureBootstrapMarker($pdo);
    fwrite(STDOUT, "[render-init] Database schema loaded successfully.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "[render-init] Schema load failed: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
