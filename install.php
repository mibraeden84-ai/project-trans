<?php
// Translink File Library - PostgreSQL installer
// Run: php install.php OR open http://localhost:8000/install.php

require_once __DIR__ . '/config.php';

$output = [];
$output[] = "Translink File Library - PostgreSQL Installer";
$output[] = "============================================";
$output[] = "";

try {
    $adminDsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_MAINTENANCE_DB;
    $admin = new PDO($adminDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $stmt = $admin->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
    $stmt->execute([DB_NAME]);
    if (!$stmt->fetchColumn()) {
        $admin->exec("CREATE DATABASE " . quoteIdentifier(DB_NAME) . " WITH ENCODING 'UTF8'");
        $output[] = "[OK] Database '" . DB_NAME . "' created";
    } else {
        $output[] = "[OK] Database '" . DB_NAME . "' already exists";
    }
} catch (PDOException $e) {
    $output[] = "[ERROR] PostgreSQL maintenance connection failed: " . $e->getMessage();
    $output[] = "Check DB_HOST, DB_PORT, DB_USER, DB_PASS, and DB_MAINTENANCE_DB in config.php or environment variables.";
    outputResult($output);
    exit(1);
}

try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $output[] = "[OK] Connected to PostgreSQL database";
} catch (PDOException $e) {
    $output[] = "[ERROR] PostgreSQL database connection failed: " . $e->getMessage();
    outputResult($output);
    exit(1);
}

$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    $output[] = "[ERROR] schema.sql not found";
    outputResult($output);
    exit(1);
}

try {
    $pdo->exec(file_get_contents($schemaFile));
    $output[] = "[OK] Schema and seed data applied";
} catch (PDOException $e) {
    $output[] = "[ERROR] Schema failed: " . $e->getMessage();
    outputResult($output);
    exit(1);
}

try {
    $brandCount = $pdo->query("SELECT COUNT(*) as c FROM brands")->fetch()['c'];
    $modelCount = $pdo->query("SELECT COUNT(*) as c FROM device_models")->fetch()['c'];
    $userCount = $pdo->query("SELECT COUNT(*) as c FROM users")->fetch()['c'];
    $output[] = "[OK] Seed check: $brandCount brands, $modelCount models, $userCount users";
} catch (PDOException $e) {
    $output[] = "[WARN] Seed check failed: " . $e->getMessage();
}

$output[] = "";
$output[] = "============================================";
$output[] = "Installation complete";
$output[] = "   Database: PostgreSQL - " . DB_NAME;
$output[] = "   Admin:    admin / password";
$output[] = "   User:     user / password";
$output[] = "   Site:     http://localhost:8000";
$output[] = "============================================";

outputResult($output);

function quoteIdentifier($identifier) {
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function outputResult($lines) {
    $text = implode("\n", $lines);
    if (PHP_SAPI === 'cli') {
        echo $text;
    } else {
        echo '<!DOCTYPE html><html><head><title>Install - Translink File Library</title>';
        echo '<style>body{font-family:monospace;padding:40px;background:#1a1a2e;color:#00ff88;line-height:1.6}.container{max-width:720px;margin:0 auto}pre{white-space:pre-wrap}a{color:#00ff88}</style></head><body>';
        echo '<div class="container"><pre>' . htmlspecialchars($text) . '</pre>';
        echo '<p><a href="index.php" style="font-size:1.2rem">Go to Library</a></p></div></body></html>';
    }
}
