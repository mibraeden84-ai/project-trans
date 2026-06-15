<?php
// Router for PHP built-in development server
// Usage: php -S localhost:8000 router.php

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Route /api/* requests to API entry point
if (str_starts_with($path, '/api')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Serve existing files directly (skip directories to allow index.php routing)
$filePath = __DIR__ . $path;
if ($path !== '/' && is_file($filePath)) {
    return false;
}

// Route install.php directly
if ($path === '/install.php' || $path === '/check.php') {
    return false;
}

// All other requests go through the main router
$_GET['page'] = ltrim($path, '/') ?: 'home';
require __DIR__ . '/index.php';
